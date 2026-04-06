<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use PDO;

#[Route('/marketplace', name: 'app_marketplace')]
class MarketplaceController extends AbstractController
{
    // ── Hardcoded current user (replace with real auth later) ──────────────────
    private string $CURRENT_USER_ID = '1';

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = new PDO(
            'mysql:host=localhost;dbname=wonderlust_db;charset=utf8mb4',
            'root',
            '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  ROLE SELECTION — /marketplace
    // ══════════════════════════════════════════════════════════════════════════

    #[Route('', name: '')]
    public function roleSelection(): Response
    {
        return $this->render('marketplace/role_selection.html.twig');
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

        $sql = 'SELECT * FROM products 
                WHERE userId != ? 
                AND (quantity - COALESCE(reserved_quantity,0)) > 0';
        $params = [$this->CURRENT_USER_ID];

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

        if ($sort === 'low')       $sql .= ' ORDER BY price ASC';
        elseif ($sort === 'high')  $sql .= ' ORDER BY price DESC';
        else                       $sql .= ' ORDER BY created_date DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Add available_quantity to each product
        foreach ($products as &$p) {
            $p['available_quantity'] = max(0, $p['quantity'] - ($p['reserved_quantity'] ?? 0));
        }

        // Cart badge count
        $cartCount = $this->getCartItemCount($this->CURRENT_USER_ID);

        return $this->render('marketplace/buyer_home.html.twig', [
            'products'    => $products,
            'cartCount'   => $cartCount,
            'search'      => $search,
            'category'    => $category,
            'type'        => $type,
            'sort'        => $sort,
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

        $sql = 'SELECT * FROM products WHERE userId = ?';
        $params = [$this->CURRENT_USER_ID];

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

    // ══════════════════════════════════════════════════════════════════════════
    //  ADD PRODUCT
    // ══════════════════════════════════════════════════════════════════════════

    #[Route('/seller/add', name: '_product_add', methods: ['GET', 'POST'])]
    public function addProduct(Request $request): Response
    {
        if ($request->isMethod('POST')) {
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
                $this->CURRENT_USER_ID,
            ]);
            $this->addFlash('success', '✅ Product added successfully!');
            return $this->redirectToRoute('app_marketplace_seller');
        }

        return $this->render('marketplace/product_form.html.twig', [
            'product' => null,
            'action'  => 'Add',
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  EDIT PRODUCT
    // ══════════════════════════════════════════════════════════════════════════

    #[Route('/seller/edit/{id}', name: '_product_edit', methods: ['GET', 'POST'])]
    public function editProduct(int $id, Request $request): Response
    {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE id = ? AND userId = ?');
        $stmt->execute([$id, $this->CURRENT_USER_ID]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            throw $this->createNotFoundException('Product not found.');
        }

        if ($request->isMethod('POST')) {
            $stmt = $this->pdo->prepare(
                'UPDATE products SET title=?, description=?, type=?, price=?, quantity=?, category=?, image=?
                 WHERE id=? AND userId=?'
            );
            $stmt->execute([
                $request->request->get('title'),
                $request->request->get('description'),
                $request->request->get('type'),
                $request->request->get('price'),
                $request->request->get('quantity'),
                $request->request->get('category'),
                $request->request->get('image', $product['image']),
                $id,
                $this->CURRENT_USER_ID,
            ]);
            $this->addFlash('success', '✅ Product updated successfully!');
            return $this->redirectToRoute('app_marketplace_seller');
        }

        return $this->render('marketplace/product_form.html.twig', [
            'product' => $product,
            'action'  => 'Edit',
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  DELETE PRODUCT
    // ══════════════════════════════════════════════════════════════════════════

    #[Route('/seller/delete/{id}', name: '_product_delete', methods: ['POST'])]
    public function deleteProduct(int $id): Response
    {
        $stmt = $this->pdo->prepare('DELETE FROM products WHERE id = ? AND userId = ?');
        $stmt->execute([$id, $this->CURRENT_USER_ID]);
        $this->addFlash('success', '🗑️ Product deleted.');
        return $this->redirectToRoute('app_marketplace_seller');
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  PRODUCT DETAILS
    // ══════════════════════════════════════════════════════════════════════════

    #[Route('/product/{id}', name: '_product_details')]
    public function productDetails(int $id): Response
    {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            throw $this->createNotFoundException('Product not found.');
        }

        $product['available_quantity'] = max(0, $product['quantity'] - ($product['reserved_quantity'] ?? 0));
        $cartCount = $this->getCartItemCount($this->CURRENT_USER_ID);

        return $this->render('marketplace/product_details.html.twig', [
            'product'   => $product,
            'cartCount' => $cartCount,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  CART
    // ══════════════════════════════════════════════════════════════════════════

    #[Route('/cart', name: '_cart')]
    public function cart(): Response
    {
        $cartId    = $this->getOrCreateCart($this->CURRENT_USER_ID);
        $cartItems = $this->getCartItemsDetailed($cartId);
        $total     = array_reduce($cartItems, fn($sum, $item) => $sum + ($item['price'] * $item['cart_qty']), 0);

        return $this->render('marketplace/cart.html.twig', [
            'cartItems' => $cartItems,
            'total'     => $total,
            'cartCount' => count($cartItems),
        ]);
    }

    #[Route('/cart/add', name: '_cart_add', methods: ['POST'])]
    public function addToCart(Request $request): Response
    {
        $productId = (int) $request->request->get('product_id');
        $quantity  = (int) $request->request->get('quantity', 1);

        // Get product & check stock
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            $this->addFlash('error', 'Product not found.');
            return $this->redirectToRoute('app_marketplace_buyer');
        }

        $available = max(0, $product['quantity'] - ($product['reserved_quantity'] ?? 0));
        if ($available < $quantity) {
            $this->addFlash('error', "Not enough stock. Available: $available");
            return $this->redirectToRoute('app_marketplace_buyer');
        }

        $cartId = $this->getOrCreateCart($this->CURRENT_USER_ID);

        // Check if already in cart
        $stmt = $this->pdo->prepare('SELECT quantity FROM cart_item WHERE cart_id = ? AND product_id = ?');
        $stmt->execute([$cartId, $productId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $newQty = $existing['quantity'] + $quantity;
            if ($newQty > $available) {
                $this->addFlash('error', 'Cannot add more than available stock.');
                return $this->redirectToRoute('app_marketplace_buyer');
            }
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

    // ══════════════════════════════════════════════════════════════════════════
    //  CHECKOUT
    // ══════════════════════════════════════════════════════════════════════════

    #[Route('/checkout', name: '_checkout')]
    public function checkout(): Response
    {
        $cartId    = $this->getOrCreateCart($this->CURRENT_USER_ID);
        $cartItems = $this->getCartItemsDetailed($cartId);
        $total     = array_reduce($cartItems, fn($sum, $item) => $sum + ($item['price'] * $item['cart_qty']), 0);

        if (empty($cartItems)) {
            $this->addFlash('error', 'Your cart is empty.');
            return $this->redirectToRoute('app_marketplace_cart');
        }

        return $this->render('marketplace/checkout.html.twig', [
            'cartItems' => $cartItems,
            'total'     => $total,
        ]);
    }

    #[Route('/checkout/confirm', name: '_checkout_confirm', methods: ['POST'])]
    public function confirmOrder(Request $request): Response
    {
        $paymentMethod = $request->request->get('payment_method', 'cash');
        $cartId        = $this->getOrCreateCart($this->CURRENT_USER_ID);
        $cartItems     = $this->getCartItemsDetailed($cartId);

        if (empty($cartItems)) {
            $this->addFlash('error', 'Cart is empty.');
            return $this->redirectToRoute('app_marketplace_cart');
        }

        $total = array_reduce($cartItems, fn($sum, $item) => $sum + ($item['price'] * $item['cart_qty']), 0);

        // Re-check stock
        foreach ($cartItems as $item) {
            $stmt = $this->pdo->prepare('SELECT quantity, reserved_quantity FROM products WHERE id = ?');
            $stmt->execute([$item['product_id']]);
            $fresh     = $stmt->fetch(PDO::FETCH_ASSOC);
            $available = max(0, $fresh['quantity'] - ($fresh['reserved_quantity'] ?? 0));
            if ($available < $item['cart_qty']) {
                $this->addFlash('error', "Not enough stock for: {$item['product_title']}");
                return $this->redirectToRoute('app_marketplace_cart');
            }
        }

        // Begin transaction
        $this->pdo->beginTransaction();
        try {
            $deliveryStatus = ($paymentMethod === 'online') ? 'confirmed' : 'pending';

            // Create facture
            $stmt = $this->pdo->prepare(
                'INSERT INTO facture (user_id, date_facture, total_price, delivery_status,payment_method)
                 VALUES (?, NOW(), ?, ?, ?)'
            );
            $stmt->execute([$this->CURRENT_USER_ID, $total, $deliveryStatus, $paymentMethod]);
            $factureId = (int) $this->pdo->lastInsertId();

            // Save delivery address
            $stmt = $this->pdo->prepare(
                'INSERT INTO delivery_address (facture_id, full_name, phone, address, city, postal_code, notes, email)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $factureId,
                $request->request->get('full_name'),
                $request->request->get('phone'),
                $request->request->get('address'),
                $request->request->get('city'),
                $request->request->get('postal_code', ''),
                $request->request->get('notes', ''),
                $request->request->get('email', ''),
            ]);

            // Update stock + save line items
            foreach ($cartItems as $item) {
                if ($paymentMethod === 'online') {
                    $stmt = $this->pdo->prepare('UPDATE products SET quantity = quantity - ? WHERE id = ?');
                } else {
                    $stmt = $this->pdo->prepare('UPDATE products SET reserved_quantity = reserved_quantity + ? WHERE id = ?');
                }
                $stmt->execute([$item['cart_qty'], $item['product_id']]);

                $stmt = $this->pdo->prepare(
                    'INSERT INTO facture_product (facture_id, product_id, product_title, quantity, price, product_image)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $factureId,
                    $item['product_id'],
                    $item['product_title'],
                    $item['cart_qty'],
                    $item['price'],
                    $item['image'],
                ]);
            }

            // Clear cart
            $stmt = $this->pdo->prepare('DELETE FROM cart_item WHERE cart_id = ?');
            $stmt->execute([$cartId]);

            $this->pdo->commit();
            $this->addFlash('success', '🎉 Order placed successfully!');
            return $this->redirectToRoute('app_marketplace_orders');

        } catch (\Exception $e) {
            $this->pdo->rollBack();
            $this->addFlash('error', 'Error processing order: ' . $e->getMessage());
            return $this->redirectToRoute('app_marketplace_cart');
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  MY ORDERS (buyer: invoices list)
    // ══════════════════════════════════════════════════════════════════════════

    #[Route('/orders', name: '_orders')]
    public function orders(): Response
    {
        $stmt = $this->pdo->prepare('SELECT * FROM facture WHERE user_id = ? ORDER BY date_facture DESC');
        $stmt->execute([$this->CURRENT_USER_ID]);
        $factures = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->render('marketplace/orders.html.twig', [
            'factures' => $factures,
        ]);
    }

    #[Route('/orders/{id}', name: '_order_detail')]
    public function orderDetail(int $id): Response
    {
        $stmt = $this->pdo->prepare('SELECT * FROM facture WHERE id_facture = ? AND user_id = ?');
        $stmt->execute([$id, $this->CURRENT_USER_ID]);
        $facture = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$facture) {
            throw $this->createNotFoundException('Order not found.');
        }

        $stmt = $this->pdo->prepare('SELECT * FROM facture_product WHERE facture_id = ?');
        $stmt->execute([$id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $this->pdo->prepare('SELECT * FROM delivery_address WHERE facture_id = ?');
        $stmt->execute([$id]);
        $address = $stmt->fetch(PDO::FETCH_ASSOC);

        return $this->render('marketplace/order_detail.html.twig', [
            'facture' => $facture,
            'items'   => $items,
            'address' => $address,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  SELLER ORDERS MANAGEMENT
    // ══════════════════════════════════════════════════════════════════════════

    #[Route('/seller/orders', name: '_seller_orders')]
    public function sellerOrders(): Response
    {
        // Orders that include at least one product owned by this seller
        $sql = '
            SELECT DISTINCT f.*, da.full_name, da.city, da.phone
            FROM facture f
            JOIN facture_product fp ON fp.facture_id = f.id_facture
            JOIN products p ON p.id = fp.product_id
            LEFT JOIN delivery_address da ON da.facture_id = f.id_facture
            WHERE p.userId = ?
            ORDER BY f.date_facture DESC
        ';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$this->CURRENT_USER_ID]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->render('marketplace/seller_orders.html.twig', [
            'orders' => $orders,
        ]);
    }

    #[Route('/seller/orders/{id}/confirm', name: '_seller_order_confirm', methods: ['POST'])]
    public function confirmDelivery(int $id): Response
    {
        // Confirm: delivery_status = confirmed, deduct reserved stock
        $stmt = $this->pdo->prepare('SELECT * FROM facture WHERE id_facture = ?');
        $stmt->execute([$id]);
        $facture = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($facture && $facture['payment_method'] === 'cash' && $facture['delivery_status'] === 'pending') {
            $this->pdo->beginTransaction();
            try {
                // Deduct stock, release reservation
                $stmt = $this->pdo->prepare('SELECT * FROM facture_product WHERE facture_id = ?');
                $stmt->execute([$id]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($items as $item) {
                    $stmt = $this->pdo->prepare(
                        'UPDATE products SET quantity = quantity - ?, reserved_quantity = reserved_quantity - ? WHERE id = ?'
                    );
                    $stmt->execute([$item['quantity'], $item['quantity'], $item['product_id']]);
                }

                $stmt = $this->pdo->prepare("UPDATE facture SET delivery_status = 'confirmed' WHERE id_facture = ?");
                $stmt->execute([$id]);

                $this->pdo->commit();
                $this->addFlash('success', '✅ Order confirmed & stock deducted.');
            } catch (\Exception $e) {
                $this->pdo->rollBack();
                $this->addFlash('error', 'Error confirming order.');
            }
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
                // Release reservation
                $stmt = $this->pdo->prepare('SELECT * FROM facture_product WHERE facture_id = ?');
                $stmt->execute([$id]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($items as $item) {
                    $stmt = $this->pdo->prepare(
                        'UPDATE products SET reserved_quantity = reserved_quantity - ? WHERE id = ?'
                    );
                    $stmt->execute([$item['quantity'], $item['product_id']]);
                }

                $stmt = $this->pdo->prepare("UPDATE facture SET delivery_status = 'cancelled' WHERE id_facture = ?");
                $stmt->execute([$id]);

                $this->pdo->commit();
                $this->addFlash('success', '❌ Order cancelled. Stock released.');
            } catch (\Exception $e) {
                $this->pdo->rollBack();
                $this->addFlash('error', 'Error cancelling order.');
            }
        }

        return $this->redirectToRoute('app_marketplace_seller_orders');
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  STATS DASHBOARD (seller)
    // ══════════════════════════════════════════════════════════════════════════

    #[Route('/seller/dashboard', name: '_seller_dashboard')]
    public function sellerDashboard(): Response
    {
        // Total products
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM products WHERE userId = ?');
        $stmt->execute([$this->CURRENT_USER_ID]);
        $totalProducts = $stmt->fetchColumn();

        // Total sales (confirmed orders)
        $stmt = $this->pdo->prepare('
            SELECT COUNT(DISTINCT f.id_facture), COALESCE(SUM(f.total_price), 0)
            FROM facture f
            JOIN facture_product fp ON fp.facture_id = f.id_facture
            JOIN products p ON p.id = fp.product_id
            WHERE p.userId = ? AND f.delivery_status = "confirmed"
        ');
        $stmt->execute([$this->CURRENT_USER_ID]);
        [$totalOrders, $totalRevenue] = $stmt->fetch(PDO::FETCH_NUM);

        // Pending orders
        $stmt = $this->pdo->prepare('
            SELECT COUNT(DISTINCT f.id_facture)
            FROM facture f
            JOIN facture_product fp ON fp.facture_id = f.id_facture
            JOIN products p ON p.id = fp.product_id
            WHERE p.userId = ? AND f.delivery_status = "pending"
        ');
        $stmt->execute([$this->CURRENT_USER_ID]);
        $pendingOrders = $stmt->fetchColumn();

        // Top products by sales
        $stmt = $this->pdo->prepare('
            SELECT p.title, p.image, SUM(fp.quantity) AS sold, SUM(fp.quantity * fp.price) AS revenue
            FROM facture_product fp
            JOIN products p ON p.id = fp.product_id
            JOIN facture f ON f.id_facture = fp.facture_id
            WHERE p.userId = ? AND f.delivery_status = "confirmed"
            GROUP BY p.id
            ORDER BY sold DESC
            LIMIT 5
        ');
        $stmt->execute([$this->CURRENT_USER_ID]);
        $topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->render('marketplace/seller_dashboard.html.twig', [
            'totalProducts' => $totalProducts,
            'totalOrders'   => $totalOrders ?? 0,
            'totalRevenue'  => $totalRevenue ?? 0,
            'pendingOrders' => $pendingOrders ?? 0,
            'topProducts'   => $topProducts,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  HELPERS
    // ══════════════════════════════════════════════════════════════════════════

    private function getOrCreateCart(string $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM cart WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return (int) $row['id'];

        $stmt = $this->pdo->prepare('INSERT INTO cart (user_id, total_price) VALUES (?, 0.00)');
        $stmt->execute([$userId]);
        return (int) $this->pdo->lastInsertId();
    }

    private function getCartItemCount(string $userId): int
    {
        $cartId = $this->getOrCreateCart($userId);
        $stmt   = $this->pdo->prepare('SELECT COALESCE(SUM(quantity),0) FROM cart_item WHERE cart_id = ?');
        $stmt->execute([$cartId]);
        return (int) $stmt->fetchColumn();
    }

    private function getCartItemsDetailed(int $cartId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT ci.quantity AS cart_qty, p.id AS product_id,
                   p.title AS product_title, p.price, p.image,
                   p.quantity AS stock, p.reserved_quantity,
                   p.category, p.type
            FROM cart_item ci
            JOIN products p ON ci.product_id = p.id
            WHERE ci.cart_id = ?
        ');
        $stmt->execute([$cartId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
