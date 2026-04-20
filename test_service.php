<?php
require 'vendor/autoload.php';

// Define APP_ENV explicitly just in case
$_SERVER['APP_ENV'] = 'dev';
require 'config/bootstrap.php';

use App\Kernel;
$kernel = new Kernel('dev', true);
$kernel->boot();

$container = $kernel->getContainer();
$em = $container->get('doctrine')->getManager();

try {
    $reservation = $em->getRepository(App\Entity\Reservations::class)->findOneBy([]);
    if(!$reservation) {
        echo "No reservation found in DB.\n";
        exit;
    }

    echo "Testing reservation ID: " . $reservation->getId() . " for email: " . $reservation->getEmail() . "\n";
    $ticketService = $container->get(App\Service\TicketIssuerService::class);
    $ticketService->sendVirtualTicket($reservation);
    echo "Sent ticket gracefully!\n";
} catch (\Exception $e) {
    echo "EXCEPTION THROWN: \n" . $e->getMessage() . "\n";
}
