<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use PDO;

#[Route('/marketplace', name: 'app_marketplace')]
class MarketplaceController extends AbstractController
{
    // ── Current user — change this to real auth when ready ────────────────────
    private string $CURRENT_USER_ID = '1';

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = new PDO(
            'mysql:host=localhost;dbname=3a19;charset=utf8mb4',
            'root',
            '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    // ── Helper: is the current user admin? ───────────────────────────────────
    private function isAdmin(): bool
    {
        return $this->CURRENT_USER_ID === 'admin';
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  ROLE SELECTION — auto-routes admin to admin panel
    // ══════════════════════════════════════════════════════════════════════════

    #[Route('', name: '')]
    public function roleSelection(): Response
    {
        if ($this->isAdmin()) {
            return $this->redirectToRoute('app_marketplace_admin');
        }
        return $this->render('marketplace/role_selection.html.twig');
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  ADMIN ENTRY POINT
    // ══════════════════════════════════════════════════════════════════════════

    #[Route('/admin', name: '_admin')]
    public function adminHome(): Response
    {
        return $this->render('marketplace/admin/role_selection_admin.html.twig');
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  ADMIN — ALL PRODUCTS (every user's products)
    // ══════════════════════════════════════════════════════════════════════════

    #[Route('/product_management', name: '_product_management')]
    public function productManagement(Request $request): Response
    {
        $search   = $request->query->get('search', '');
        $category = $request->query->get('category', '');
        $type     = $request->query->get('type', '');
        $userId   = $request->query->get('user_filter', '');

        $sql    = 'SELECT * FROM products WHERE 1=1';
        $params = [];

        if ($search) {
            $sql .= ' AND (title LIKE ? OR description LIKE ?)';
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        if ($category && $category !== 'all') {
            $sql .= ' AND category = ?';
            $params[] = $category;
        }
        if ($type && $type !== 'all') {
            $sql .= ' AND type = ?';
            $params[] = $type;
        }
        if ($userId && $userId !== 'all') {
            $sql .= ' AND userId = ?';
            $params[] = $userId;
        }
        $sql .= ' ORDER BY userId, created_date DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($products as &$p) {
            $p['available_quantity'] = max(0, $p['quantity'] - ($p['reserved_quantity'] ?? 0));
        }

        // Get distinct userIds for filter dropdown
        $stmt    = $this->pdo->query('SELECT DISTINCT userId FROM products ORDER BY userId');
        $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return $this->render('marketplace/admin/product_management.html.twig', [
            'products'     => $products,
            'search'       => $search,
            'category'     => $category,
            'type'         => $type,
            'user_filter'  => $userId,
            'userIds'      => $userIds,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  ADMIN — ADD PRODUCT (admin chooses userId)
    // ══════════════════════════════════════════════════════════════════════════

    #[Route('/admin/product/add', name: '_admin_product_add', methods: ['GET', 'POST'])]
    public function adminAddProduct(Request $request): Response
    {
        // Get existing userIds for suggestions
        $stmt    = $this->pdo->query('SELECT DISTINCT userId FROM products ORDER BY userId');
        $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if ($request->isMethod('POST')) {
            $targetUserId = trim($request->request->get('userId', 'admin'));
            if (empty($targetUserId)) $targetUserId = 'admin';

            $stmt = $this->pdo->prepare(
                'INSERT INTO products (title, description, type, price, quantity, category, image, created_date, userId)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)'
            );
            $stmt->execute([
                $request->request->get('title'),
                $request->request->get('description'),
                $request->request->get('type'),
                $request->request->get('price'),
                $request->request->get('quantity'),
                $request->request->get('category'),
                $request->request->get('image', ''),
                $targetUserId,
            ]);
            $this->addFlash('success', "✅ Product added for user: $targetUserId");
            return $this->redirectToRoute('app_marketplace_product_management');
        }

        return $this->render('marketplace/admin/product_form_admin.html.twig', [
            'product' => null,
            'action'  => 'Add',
            'userIds' => $userIds,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  ADMIN — EDIT PRODUCT (can change any field including userId)
    // ══════════════════════════════════════════════════════════════════════════

    #[Route('/admin/product/edit/{id}', name: '_admin_product_edit', methods: ['GET', 'POST'])]
    public function adminEditProduct(int $id, Request $request): Response
    {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            throw $this->createNotFoundException('Product not found.');
        }

        $stmt    = $this->pdo->query('SELECT DISTINCT userId FROM products ORDER BY userId');
        $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if ($request->isMethod('POST')) {
            $targetUserId = trim($request->request->get('userId', $product['userId']));
            if (empty($targetUserId)) $targetUserId = $product['userId'];

            $stmt = $this->pdo->prepare(
                'UPDATE products SET title=?, description=?, type=?, price=?, quantity=?,
                 reserved_quantity=?, category=?, image=?, userId=?
                 WHERE id=?'
            );
            $stmt->execute([
                $request->request->get('title'),
                $request->request->get('description'),
                $request->request->get('type'),
                $request->request->get('price'),
                $request->request->get('quantity'),
                $request->request->get('reserved_quantity', 0),
                $request->request->get('category'),
                $request->request->get('image', $product['image']),
                $targetUserId,
                $id,
            ]);
            $this->addFlash('success', '✅ Product updated successfully!');
            return $this->redirectToRoute('app_marketplace_product_management');
        }

        return $this->render('marketplace/admin/product_form_admin.html.twig', [
            'product' => $product,
            'action'  => 'Edit',
            'userIds' => $userIds,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  ADMIN — DELETE ANY PRODUCT
    // ══════════════════════════════════════════════════════════════════════════

    #[Route('/admin/product/delete/{id}', name: '_admin_product_delete', methods: ['POST'])]
    public function adminDeleteProduct(int $id): Response
    {
        $stmt = $this->pdo->prepare('DELETE FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $this->addFlash('success', '🗑️ Product deleted.');
        return $this->redirectToRoute('app_marketplace_product_management');
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  ADMIN — GLOBAL DASHBOARD (stats globales + par userId)
    // ══════════════════════════════════════════════════════════════════════════

    #[Route('/admin/dashboard', name: '_admin_dashboard')]
    public function adminDashboard(): Response
    {
        // ── Global stats ─────────────────────────────────────────────────────
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM products');
        $totalProducts = $stmt->fetchColumn();

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM facture WHERE delivery_status = "confirmed"');
        $totalOrders = $stmt->fetchColumn();

        $stmt = $this->pdo->query('SELECT COALESCE(SUM(total_price),0) FROM facture WHERE delivery_status = "confirmed"');
        $totalRevenue = $stmt->fetchColumn();

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM facture WHERE delivery_status = "pending"');
        $pendingOrders = $stmt->fetchColumn();

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM cart_item');
        $itemsInCarts = $stmt->fetchColumn();

        // ── Per-user stats ───────────────────────────────────────────────────
        $stmt    = $this->pdo->query('SELECT DISTINCT userId FROM products ORDER BY userId');
        $sellers = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $sellerStats = [];
        foreach ($sellers as $sellerId) {
            // Products count
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM products WHERE userId = ?');
            $stmt->execute([$sellerId]);
            $prodCount = $stmt->fetchColumn();

            // Confirmed orders & revenue for this seller
            $stmt = $this->pdo->prepare('
                SELECT COUNT(DISTINCT f.id_facture), COALESCE(SUM(fp.quantity * fp.price), 0)
                FROM facture f
                JOIN facture_product fp ON fp.facture_id = f.id_facture
                JOIN products p ON p.id = fp.product_id
                WHERE p.userId = ? AND f.delivery_status = "confirmed"
            ');
            $stmt->execute([$sellerId]);
            [$sellerOrders, $sellerRevenue] = $stmt->fetch(PDO::FETCH_NUM);

            // Pending for this seller
            $stmt = $this->pdo->prepare('
                SELECT COUNT(DISTINCT f.id_facture)
                FROM facture f
                JOIN facture_product fp ON fp.facture_id = f.id_facture
                JOIN products p ON p.id = fp.product_id
                WHERE p.userId = ? AND f.delivery_status = "pending"
            ');
            $stmt->execute([$sellerId]);
            $sellerPending = $stmt->fetchColumn();

            // Top product for this seller
            $stmt = $this->pdo->prepare('
                SELECT p.title, SUM(fp.quantity) AS sold
                FROM facture_product fp
                JOIN products p ON p.id = fp.product_id
                JOIN facture f ON f.id_facture = fp.facture_id
                WHERE p.userId = ? AND f.delivery_status = "confirmed"
                GROUP BY p.id ORDER BY sold DESC LIMIT 1
            ');
            $stmt->execute([$sellerId]);
            $topProduct = $stmt->fetch(PDO::FETCH_ASSOC);

            $sellerStats[] = [
                'userId'        => $sellerId,
                'products'      => $prodCount,
                'orders'        => $sellerOrders ?? 0,
                'revenue'       => $sellerRevenue ?? 0,
                'pending'       => $sellerPending ?? 0,
                'top_product'   => $topProduct,
            ];
        }

        // ── Also get buyer stats (users who placed orders but may not sell) ──
        $stmt = $this->pdo->query('SELECT DISTINCT user_id FROM facture ORDER BY user_id');
        $buyers = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $buyerStats = [];
        foreach ($buyers as $buyerId) {
            $stmt = $this->pdo->prepare('
                SELECT COUNT(*), COALESCE(SUM(total_price),0),
                       SUM(CASE WHEN delivery_status="pending" THEN 1 ELSE 0 END),
                       SUM(CASE WHEN delivery_status="confirmed" THEN 1 ELSE 0 END),
                       SUM(CASE WHEN delivery_status="cancelled" THEN 1 ELSE 0 END)
                FROM facture WHERE user_id = ?
            ');
            $stmt->execute([$buyerId]);
            [$totalF, $totalSpent, $pendingF, $confirmedF, $cancelledF] = $stmt->fetch(PDO::FETCH_NUM);

            $buyerStats[] = [
                'userId'    => $buyerId,
                'orders'    => $totalF,
                'spent'     => $totalSpent,
                'pending'   => $pendingF,
                'confirmed' => $confirmedF,
                'cancelled' => $cancelledF,
            ];
        }

        return $this->render('marketplace/admin/dashboard.html.twig', [
            'totalProducts' => $totalProducts,
            'totalOrders'   => $totalOrders,
            'totalRevenue'  => $totalRevenue,
            'pendingOrders' => $pendingOrders,
            'itemsInCarts'  => $itemsInCarts,
            'sellerStats'   => $sellerStats,
            'buyerStats'    => $buyerStats,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  ADMIN — ALL ORDERS (every user's factures)
    // ══════════════════════════════════════════════════════════════════════════

    #[Route('/admin/orders', name: '_admin_orders')]
    public function adminOrders(Request $request): Response
    {
        $statusFilter = $request->query->get('status', '');
        $userFilter   = $request->query->get('user_filter', '');

        $sql    = 'SELECT f.*, da.full_name, da.city, da.phone, da.email FROM facture f LEFT JOIN delivery_address da ON da.facture_id = f.id_facture WHERE 1=1';
        $params = [];

        if ($statusFilter && $statusFilter !== 'all') {
            $sql .= ' AND f.delivery_status = ?';
            $params[] = $statusFilter;
        }
        if ($userFilter && $userFilter !== 'all') {
            $sql .= ' AND f.user_id = ?';
            $params[] = $userFilter;
        }

        $sql .= ' ORDER BY f.date_facture DESC';

        $stmt   = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Attach items to each order
        foreach ($orders as &$order) {
            $stmt2 = $this->pdo->prepare('SELECT fp.*, p.userId AS seller_id FROM facture_product fp LEFT JOIN products p ON p.id = fp.product_id WHERE fp.facture_id = ?');
            $stmt2->execute([$order['id_facture']]);
            $order['items'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        }

        // Distinct buyers for filter
        $stmt    = $this->pdo->query('SELECT DISTINCT user_id FROM facture ORDER BY user_id');
        $buyerIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return $this->render('marketplace/admin/all_orders.html.twig', [
            'orders'       => $orders,
            'buyerIds'     => $buyerIds,
            'statusFilter' => $statusFilter,
            'userFilter'   => $userFilter,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  ADMIN — CONFIRM ANY ORDER
    // ══════════════════════════════════════════════════════════════════════════

    #[Route('/admin/orders/{id}/confirm', name: '_admin_order_confirm', methods: ['POST'])]
    public function adminConfirmOrder(int $id): Response
    {
        $stmt = $this->pdo->prepare('SELECT * FROM facture WHERE id_facture = ?');
        $stmt->execute([$id]);
        $facture = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($facture && $facture['delivery_status'] === 'pending') {
            $this->pdo->beginTransaction();
            try {
                $stmt = $this->pdo->prepare('SELECT * FROM facture_product WHERE facture_id = ?');
                $stmt->execute([$id]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($items as $item) {
                    if ($facture['payment_method'] === 'cash') {
                        // Release reserved, deduct actual stock
                        $stmt = $this->pdo->prepare(
                            'UPDATE products SET quantity = quantity - ?, reserved_quantity = reserved_quantity - ? WHERE id = ?'
                        );
                        $stmt->execute([$item['quantity'], $item['quantity'], $item['product_id']]);
                    }
                    // For online orders stock was already deducted at purchase
                }

                $stmt = $this->pdo->prepare("UPDATE facture SET delivery_status = 'confirmed' WHERE id_facture = ?");
                $stmt->execute([$id]);

                $this->pdo->commit();
                $this->addFlash('success', "✅ Order #$id confirmed.");
            } catch (\Exception $e) {
                $this->pdo->rollBack();
                $this->addFlash('error', 'Error: ' . $e->getMessage());
            }
        } else {
            $this->addFlash('error', 'Cannot confirm — order is not pending.');
        }

        return $this->redirectToRoute('app_marketplace_admin_orders');
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  ADMIN — CANCEL ANY ORDER
    // ══════════════════════════════════════════════════════════════════════════

    #[Route('/admin/orders/{id}/cancel', name: '_admin_order_cancel', methods: ['POST'])]
    public function adminCancelOrder(int $id): Response
    {
        $stmt = $this->pdo->prepare('SELECT * FROM facture WHERE id_facture = ?');
        $stmt->execute([$id]);
        $facture = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($facture && $facture['delivery_status'] === 'pending') {
            $this->pdo->beginTransaction();
            try {
                $stmt = $this->pdo->prepare('SELECT * FROM facture_product WHERE facture_id = ?');
                $stmt->execute([$id]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($items as $item) {
                    // Release reserved stock
                    $stmt = $this->pdo->prepare(
                        'UPDATE products SET reserved_quantity = GREATEST(0, reserved_quantity - ?) WHERE id = ?'
                    );
                    $stmt->execute([$item['quantity'], $item['product_id']]);
                }

                $stmt = $this->pdo->prepare("UPDATE facture SET delivery_status = 'cancelled' WHERE id_facture = ?");
                $stmt->execute([$id]);

                $this->pdo->commit();
                $this->addFlash('success', "❌ Order #$id cancelled. Stock released.");
            } catch (\Exception $e) {
                $this->pdo->rollBack();
                $this->addFlash('error', 'Error: ' . $e->getMessage());
            }
        } elseif ($facture) {
            // Admin can force-cancel even confirmed orders
            $stmt = $this->pdo->prepare("UPDATE facture SET delivery_status = 'cancelled' WHERE id_facture = ?");
            $stmt->execute([$id]);
            $this->addFlash('success', "❌ Order #$id force-cancelled by admin.");
        }

        return $this->redirectToRoute('app_marketplace_admin_orders');
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  ADMIN — ALL FACTURES (flat list of all invoices)
    // ══════════════════════════════════════════════════════════════════════════

    #[Route('/admin/factures', name: '_admin_factures')]
    public function adminFactures(Request $request): Response
    {
        $userFilter   = $request->query->get('user_filter', '');
        $statusFilter = $request->query->get('status', '');

        $sql    = 'SELECT f.*, da.full_name FROM facture f LEFT JOIN delivery_address da ON da.facture_id = f.id_facture WHERE 1=1';
        $params = [];

        if ($userFilter && $userFilter !== 'all') {
            $sql .= ' AND f.user_id = ?';
            $params[] = $userFilter;
        }
        if ($statusFilter && $statusFilter !== 'all') {
            $sql .= ' AND f.delivery_status = ?';
            $params[] = $statusFilter;
        }
        $sql .= ' ORDER BY f.date_facture DESC';

        $stmt     = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $factures = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt     = $this->pdo->query('SELECT DISTINCT user_id FROM facture ORDER BY user_id');
        $buyerIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return $this->render('marketplace/admin/all_factures.html.twig', [
            'factures'     => $factures,
            'buyerIds'     => $buyerIds,
            'userFilter'   => $userFilter,
            'statusFilter' => $statusFilter,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  BUYER HOME — Browse all available products
    // ══════════════════════════════════════════════════════════════════════════

    #[Route('/buyer', name: '_buyer')]
    public function buyerHome(Request $request): Response
    {
        $search   = $request->query->get('search', '');
        $category = $request->query->get('category', '');
        $type     = $request->query->get('type', '');
        $sort     = $request->query->get('sort', '');

        $sql    = 'SELECT * FROM products WHERE userId != ? AND (quantity - COALESCE(reserved_quantity,0)) > 0';
        $params = [$this->CURRENT_USER_ID];

        if ($search) {
            $sql .= ' AND (title LIKE ? OR description LIKE ?)';
            $params[] = "%$search%"; $params[] = "%$search%";
        }
        if ($category && $category !== 'all') { $sql .= ' AND category = ?'; $params[] = $category; }
        if ($type     && $type !== 'all')     { $sql .= ' AND type = ?';     $params[] = $type; }

        if ($sort === 'low')      $sql .= ' ORDER BY price ASC';
        elseif ($sort === 'high') $sql .= ' ORDER BY price DESC';
        else                      $sql .= ' ORDER BY created_date DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($products as &$p) {
            $p['available_quantity'] = max(0, $p['quantity'] - ($p['reserved_quantity'] ?? 0));
        }

        $cartCount = $this->getCartItemCount($this->CURRENT_USER_ID);

        return $this->render('marketplace/buyer_home.html.twig', [
            'products'  => $products,
            'cartCount' => $cartCount,
            'search'    => $search,
            'category'  => $category,
            'type'      => $type,
            'sort'      => $sort,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  SELLER HOME — Manage my products
    // ══════════════════════════════════════════════════════════════════════════

    #[Route('/seller', name: '_seller')]
    public function sellerHome(Request $request): Response
    {
        $search   = $request->query->get('search', '');
        $category = $request->query->get('category', '');
        $type     = $request->query->get('type', '');

        $sql    = 'SELECT * FROM products WHERE userId = ?';
        $params = [$this->CURRENT_USER_ID];

        if ($search) {
            $sql .= ' AND (title LIKE ? OR description LIKE ?)';
            $params[] = "%$search%"; $params[] = "%$search%";
        }
        if ($category && $category !== 'all') { $sql .= ' AND category = ?'; $params[] = $category; }
        if ($type     && $type !== 'all')     { $sql .= ' AND type = ?';     $params[] = $type; }
        $sql .= ' ORDER BY created_date DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($products as &$p) {
            $p['available_quantity'] = max(0, $p['quantity'] - ($p['reserved_quantity'] ?? 0));
        }

        return $this->render('marketplace/seller_home.html.twig', [
            'products' => $products,
            'search'   => $search,
            'category' => $category,
            'type'     => $type,
        ]);
    }

    #[Route('/seller/add', name: '_product_add', methods: ['GET', 'POST'])]
    public function addProduct(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO products (title, description, type, price, quantity, category, image, created_date, userId) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)'
            );
            $stmt->execute([
                $request->request->get('title'),
                $request->request->get('description'),
                $request->request->get('type'),
                $request->request->get('price'),
                $request->request->get('quantity'),
                $request->request->get('category'),
                $request->request->get('image', ''),
                $this->CURRENT_USER_ID,
            ]);
            $this->addFlash('success', '✅ Product added successfully!');
            return $this->redirectToRoute('app_marketplace_seller');
        }
        return $this->render('marketplace/product_form.html.twig', ['product' => null, 'action' => 'Add']);
    }

    #[Route('/seller/edit/{id}', name: '_product_edit', methods: ['GET', 'POST'])]
    public function editProduct(int $id, Request $request): Response
    {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE id = ? AND userId = ?');
        $stmt->execute([$id, $this->CURRENT_USER_ID]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) throw $this->createNotFoundException('Product not found.');

        if ($request->isMethod('POST')) {
            $stmt = $this->pdo->prepare(
                'UPDATE products SET title=?, description=?, type=?, price=?, quantity=?, category=?, image=? WHERE id=? AND userId=?'
            );
            $stmt->execute([
                $request->request->get('title'), $request->request->get('description'),
                $request->request->get('type'), $request->request->get('price'),
                $request->request->get('quantity'), $request->request->get('category'),
                $request->request->get('image', $product['image']), $id, $this->CURRENT_USER_ID,
            ]);
            $this->addFlash('success', '✅ Product updated!');
            return $this->redirectToRoute('app_marketplace_seller');
        }

        return $this->render('marketplace/product_form.html.twig', ['product' => $product, 'action' => 'Edit']);
    }

    #[Route('/seller/delete/{id}', name: '_product_delete', methods: ['POST'])]
    public function deleteProduct(int $id): Response
    {
        $stmt = $this->pdo->prepare('DELETE FROM products WHERE id = ? AND userId = ?');
        $stmt->execute([$id, $this->CURRENT_USER_ID]);
        $this->addFlash('success', '🗑️ Product deleted.');
        return $this->redirectToRoute('app_marketplace_seller');
    }

    #[Route('/product/{id}', name: '_product_details')]
    public function productDetails(int $id): Response
    {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) throw $this->createNotFoundException('Product not found.');
        $product['available_quantity'] = max(0, $product['quantity'] - ($product['reserved_quantity'] ?? 0));
        return $this->render('marketplace/product_details.html.twig', [
            'product'   => $product,
            'cartCount' => $this->getCartItemCount($this->CURRENT_USER_ID),
        ]);
    }

    #[Route('/cart', name: '_cart')]
    public function cart(): Response
    {
        $cartId    = $this->getOrCreateCart($this->CURRENT_USER_ID);
        $cartItems = $this->getCartItemsDetailed($cartId);
        $total     = array_reduce($cartItems, fn($s, $i) => $s + ($i['price'] * $i['cart_qty']), 0);
        return $this->render('marketplace/cart.html.twig', ['cartItems' => $cartItems, 'total' => $total, 'cartCount' => count($cartItems)]);
    }

    #[Route('/cart/add', name: '_cart_add', methods: ['POST'])]
    public function addToCart(Request $request): Response
    {
        $productId = (int) $request->request->get('product_id');
        $quantity  = (int) $request->request->get('quantity', 1);

        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) { $this->addFlash('error', 'Product not found.'); return $this->redirectToRoute('app_marketplace_buyer'); }

        $available = max(0, $product['quantity'] - ($product['reserved_quantity'] ?? 0));
        if ($available < $quantity) { $this->addFlash('error', "Not enough stock. Available: $available"); return $this->redirectToRoute('app_marketplace_buyer'); }

        $cartId = $this->getOrCreateCart($this->CURRENT_USER_ID);
        $stmt   = $this->pdo->prepare('SELECT quantity FROM cart_item WHERE cart_id = ? AND product_id = ?');
        $stmt->execute([$cartId, $productId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $newQty = $existing['quantity'] + $quantity;
            if ($newQty > $available) { $this->addFlash('error', 'Cannot exceed available stock.'); return $this->redirectToRoute('app_marketplace_buyer'); }
            $stmt = $this->pdo->prepare('UPDATE cart_item SET quantity = ? WHERE cart_id = ? AND product_id = ?');
            $stmt->execute([$newQty, $cartId, $productId]);
        } else {
            $stmt = $this->pdo->prepare('INSERT INTO cart_item (cart_id, product_id, quantity) VALUES (?, ?, ?)');
            $stmt->execute([$cartId, $productId, $quantity]);
        }

        $this->addFlash('success', "✅ {$product['title']} added to cart!");
        return $this->redirectToRoute('app_marketplace_buyer');
    }

    #[Route('/cart/remove/{productId}', name: '_cart_remove', methods: ['POST'])]
    public function removeFromCart(int $productId): Response
    {
        $cartId = $this->getOrCreateCart($this->CURRENT_USER_ID);
        $stmt   = $this->pdo->prepare('DELETE FROM cart_item WHERE cart_id = ? AND product_id = ?');
        $stmt->execute([$cartId, $productId]);
        return $this->redirectToRoute('app_marketplace_cart');
    }

    #[Route('/cart/update', name: '_cart_update', methods: ['POST'])]
    public function updateCart(Request $request): Response
    {
        $productId = (int) $request->request->get('product_id');
        $quantity  = (int) $request->request->get('quantity');
        $cartId    = $this->getOrCreateCart($this->CURRENT_USER_ID);
        if ($quantity < 1) {
            $stmt = $this->pdo->prepare('DELETE FROM cart_item WHERE cart_id = ? AND product_id = ?');
            $stmt->execute([$cartId, $productId]);
        } else {
            $stmt = $this->pdo->prepare('UPDATE cart_item SET quantity = ? WHERE cart_id = ? AND product_id = ?');
            $stmt->execute([$quantity, $cartId, $productId]);
        }
        return $this->redirectToRoute('app_marketplace_cart');
    }

    #[Route('/checkout', name: '_checkout')]
    public function checkout(): Response
    {
        $cartId    = $this->getOrCreateCart($this->CURRENT_USER_ID);
        $cartItems = $this->getCartItemsDetailed($cartId);
        $total     = array_reduce($cartItems, fn($s, $i) => $s + ($i['price'] * $i['cart_qty']), 0);
        if (empty($cartItems)) { $this->addFlash('error', 'Cart is empty.'); return $this->redirectToRoute('app_marketplace_cart'); }
        return $this->render('marketplace/checkout.html.twig', ['cartItems' => $cartItems, 'total' => $total]);
    }

    #[Route('/checkout/confirm', name: '_checkout_confirm', methods: ['POST'])]
    public function confirmOrder(Request $request): Response
    {
        $paymentMethod = $request->request->get('payment_method', 'cash');
        $cartId        = $this->getOrCreateCart($this->CURRENT_USER_ID);
        $cartItems     = $this->getCartItemsDetailed($cartId);
        if (empty($cartItems)) { $this->addFlash('error', 'Cart is empty.'); return $this->redirectToRoute('app_marketplace_cart'); }

        $total = array_reduce($cartItems, fn($s, $i) => $s + ($i['price'] * $i['cart_qty']), 0);

        foreach ($cartItems as $item) {
            $stmt = $this->pdo->prepare('SELECT quantity, reserved_quantity FROM products WHERE id = ?');
            $stmt->execute([$item['product_id']]);
            $fresh     = $stmt->fetch(PDO::FETCH_ASSOC);
            $available = max(0, $fresh['quantity'] - ($fresh['reserved_quantity'] ?? 0));
            if ($available < $item['cart_qty']) { $this->addFlash('error', "Not enough stock: {$item['product_title']}"); return $this->redirectToRoute('app_marketplace_cart'); }
        }

        $this->pdo->beginTransaction();
        try {
            $deliveryStatus = ($paymentMethod === 'online') ? 'confirmed' : 'pending';
            $stmt = $this->pdo->prepare('INSERT INTO facture (user_id, date_facture, total_price, delivery_status, payment_method) VALUES (?, NOW(), ?, ?, ?)');
            $stmt->execute([$this->CURRENT_USER_ID, $total, $deliveryStatus, $paymentMethod]);
            $factureId = (int) $this->pdo->lastInsertId();

            $stmt = $this->pdo->prepare('INSERT INTO delivery_address (facture_id, full_name, phone, address, city, postal_code, notes, email) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$factureId, $request->request->get('full_name'), $request->request->get('phone'), $request->request->get('address'), $request->request->get('city'), $request->request->get('postal_code', ''), $request->request->get('notes', ''), $request->request->get('email', '')]);

            foreach ($cartItems as $item) {
                if ($paymentMethod === 'online') {
                    $stmt = $this->pdo->prepare('UPDATE products SET quantity = quantity - ? WHERE id = ?');
                } else {
                    $stmt = $this->pdo->prepare('UPDATE products SET reserved_quantity = reserved_quantity + ? WHERE id = ?');
                }
                $stmt->execute([$item['cart_qty'], $item['product_id']]);

                $stmt = $this->pdo->prepare('INSERT INTO facture_product (facture_id, product_id, product_title, quantity, price, product_image) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$factureId, $item['product_id'], $item['product_title'], $item['cart_qty'], $item['price'], $item['image']]);
            }

            $stmt = $this->pdo->prepare('DELETE FROM cart_item WHERE cart_id = ?');
            $stmt->execute([$cartId]);
            $this->pdo->commit();
            $this->addFlash('success', '🎉 Order placed!');
            return $this->redirectToRoute('app_marketplace_orders');
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            $this->addFlash('error', 'Error: ' . $e->getMessage());
            return $this->redirectToRoute('app_marketplace_cart');
        }
    }

    #[Route('/orders', name: '_orders')]
    public function orders(): Response
    {    if ($this->isAdmin()) {
              return $this->redirect('admin/factures');
        } 
        else {
        $stmt = $this->pdo->prepare('SELECT * FROM facture WHERE user_id = ? ORDER BY date_facture DESC');
        $stmt->execute([$this->CURRENT_USER_ID]);
        $factures = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $this->render('marketplace/orders.html.twig', ['factures' => $factures]);
        }
    }

    #[Route('/orders/{id}', name: '_order_detail')]
    public function orderDetail(int $id): Response
    {
        if ($this->isAdmin()) {
    $stmt = $this->pdo->prepare('SELECT * FROM facture WHERE id_facture = ?');
    $stmt->execute([$id]);
} else {
    $stmt = $this->pdo->prepare('SELECT * FROM facture WHERE id_facture = ? AND user_id = ?');
    $stmt->execute([$id, $this->CURRENT_USER_ID]);
}
$facture = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$facture) throw $this->createNotFoundException('Order not found.');

        $stmt = $this->pdo->prepare('SELECT * FROM facture_product WHERE facture_id = ?');
        $stmt->execute([$id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $this->pdo->prepare('SELECT * FROM delivery_address WHERE facture_id = ?');
        $stmt->execute([$id]);
        $address = $stmt->fetch(PDO::FETCH_ASSOC);

        return $this->render('marketplace/order_detail.html.twig', ['facture' => $facture, 'items' => $items, 'address' => $address]);
    }

    #[Route('/seller/orders', name: '_seller_orders')]
    public function sellerOrders(): Response
    {
        $sql = 'SELECT DISTINCT f.*, da.full_name, da.city, da.phone FROM facture f JOIN facture_product fp ON fp.facture_id = f.id_facture JOIN products p ON p.id = fp.product_id LEFT JOIN delivery_address da ON da.facture_id = f.id_facture WHERE p.userId = ? ORDER BY f.date_facture DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$this->CURRENT_USER_ID]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $this->render('marketplace/seller_orders.html.twig', ['orders' => $orders]);
    }

    #[Route('/seller/orders/{id}/confirm', name: '_seller_order_confirm', methods: ['POST'])]
    public function confirmDelivery(int $id): Response
    {
        $stmt = $this->pdo->prepare('SELECT * FROM facture WHERE id_facture = ?');
        $stmt->execute([$id]);
        $facture = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($facture && $facture['payment_method'] === 'cash' && $facture['delivery_status'] === 'pending') {
            $this->pdo->beginTransaction();
            try {
                $stmt = $this->pdo->prepare('SELECT * FROM facture_product WHERE facture_id = ?');
                $stmt->execute([$id]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                    $s = $this->pdo->prepare('UPDATE products SET quantity = quantity - ?, reserved_quantity = reserved_quantity - ? WHERE id = ?');
                    $s->execute([$item['quantity'], $item['quantity'], $item['product_id']]);
                }
                $this->pdo->prepare("UPDATE facture SET delivery_status = 'confirmed' WHERE id_facture = ?")->execute([$id]);
                $this->pdo->commit();
                $this->addFlash('success', '✅ Order confirmed.');
            } catch (\Exception $e) { $this->pdo->rollBack(); $this->addFlash('error', 'Error.'); }
        }
        return $this->redirectToRoute('app_marketplace_seller_orders');
    }

    #[Route('/seller/orders/{id}/cancel', name: '_seller_order_cancel', methods: ['POST'])]
    public function cancelOrder(int $id): Response
    {
        $stmt = $this->pdo->prepare('SELECT * FROM facture WHERE id_facture = ?');
        $stmt->execute([$id]);
        $facture = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($facture && $facture['delivery_status'] === 'pending') {
            $this->pdo->beginTransaction();
            try {
                $stmt = $this->pdo->prepare('SELECT * FROM facture_product WHERE facture_id = ?');
                $stmt->execute([$id]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                    $s = $this->pdo->prepare('UPDATE products SET reserved_quantity = reserved_quantity - ? WHERE id = ?');
                    $s->execute([$item['quantity'], $item['product_id']]);
                }
                $this->pdo->prepare("UPDATE facture SET delivery_status = 'cancelled' WHERE id_facture = ?")->execute([$id]);
                $this->pdo->commit();
                $this->addFlash('success', '❌ Order cancelled.');
            } catch (\Exception $e) { $this->pdo->rollBack(); $this->addFlash('error', 'Error.'); }
        }
        return $this->redirectToRoute('app_marketplace_seller_orders');
    }

#[Route('/seller/dashboard', name: '_seller_dashboard')]
public function sellerDashboard(): Response
{
    // ── Total Products ─────────────────────────────
    $stmt = $this->pdo->prepare('
        SELECT COUNT(*) 
        FROM products 
        WHERE userId = ?
    ');
    $stmt->execute([$this->CURRENT_USER_ID]);
    $totalProducts = $stmt->fetchColumn();

    // ── Confirmed Orders + Revenue (CORRECT) ──────
    $stmt = $this->pdo->prepare('
        SELECT 
            COUNT(DISTINCT f.id_facture) AS orders,
            COALESCE(SUM(fp.quantity * fp.price), 0) AS revenue
        FROM facture f
        JOIN facture_product fp ON fp.facture_id = f.id_facture
        JOIN products p ON p.id = fp.product_id
        WHERE p.userId = ? 
        AND f.delivery_status = "confirmed"
    ');
    $stmt->execute([$this->CURRENT_USER_ID]);
    [$totalOrders, $totalRevenue] = $stmt->fetch(PDO::FETCH_NUM);

    // ── Pending Orders ────────────────────────────
    $stmt = $this->pdo->prepare('
        SELECT COUNT(DISTINCT f.id_facture)
        FROM facture f
        JOIN facture_product fp ON fp.facture_id = f.id_facture
        JOIN products p ON p.id = fp.product_id
        WHERE p.userId = ? 
        AND f.delivery_status = "pending"
    ');
    $stmt->execute([$this->CURRENT_USER_ID]);
    $pendingOrders = $stmt->fetchColumn();

    // ── Cancelled Orders ──────────────────────────
    $stmt = $this->pdo->prepare('
        SELECT COUNT(DISTINCT f.id_facture)
        FROM facture f
        JOIN facture_product fp ON fp.facture_id = f.id_facture
        JOIN products p ON p.id = fp.product_id
        WHERE p.userId = ? 
        AND f.delivery_status = "cancelled"
    ');
    $stmt->execute([$this->CURRENT_USER_ID]);
    $cancelledOrders = $stmt->fetchColumn();

    // ── Top Products (BEST SELLERS) ───────────────
    $stmt = $this->pdo->prepare('
        SELECT 
            p.title,
            p.image,
            SUM(fp.quantity) AS sold,
            SUM(fp.quantity * fp.price) AS revenue
        FROM facture_product fp
        JOIN products p ON p.id = fp.product_id
        JOIN facture f ON f.id_facture = fp.facture_id
        WHERE p.userId = ? 
        AND f.delivery_status = "confirmed"
        GROUP BY p.id
        ORDER BY sold DESC
        LIMIT 5
    ');
    $stmt->execute([$this->CURRENT_USER_ID]);
    $topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Render ────────────────────────────────────
    return $this->render('marketplace/seller_dashboard.html.twig', [
        'totalProducts'   => $totalProducts ?? 0,
        'totalOrders'     => $totalOrders ?? 0,
        'totalRevenue'    => $totalRevenue ?? 0,
        'pendingOrders'   => $pendingOrders ?? 0,
        'cancelledOrders' => $cancelledOrders ?? 0,
        'topProducts'     => $topProducts ?? [],
    ]);
}

   

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function getOrCreateCart(string $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM cart WHERE user_id = ?'); $stmt->execute([$userId]); $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return (int) $row['id'];
        $stmt = $this->pdo->prepare('INSERT INTO cart (user_id, total_price) VALUES (?, 0.00)'); $stmt->execute([$userId]);
        return (int) $this->pdo->lastInsertId();
    }

    private function getCartItemCount(string $userId): int
    {
        $cartId = $this->getOrCreateCart($userId);
        $stmt   = $this->pdo->prepare('SELECT COALESCE(SUM(quantity),0) FROM cart_item WHERE cart_id = ?'); $stmt->execute([$cartId]);
        return (int) $stmt->fetchColumn();
    }

    private function getCartItemsDetailed(int $cartId): array
    {
        $stmt = $this->pdo->prepare('SELECT ci.quantity AS cart_qty, p.id AS product_id, p.title AS product_title, p.price, p.image, p.quantity AS stock, p.reserved_quantity, p.category, p.type FROM cart_item ci JOIN products p ON ci.product_id = p.id WHERE ci.cart_id = ?');
        $stmt->execute([$cartId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
