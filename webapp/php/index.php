<?php

if (php_sapi_name() === 'cli-server') {
    if (preg_match('/\.(?:png|jpg|jpeg|gif)$/', $_SERVER['REQUEST_URI'])) {
        return false;
    }
}

require_once 'lib/limonade.php';

function configure()
{
    option('base_uri', '');
    option('session', false);

    $env = getenv('ISUCON_ENV');
    if (! $env) $env = 'local';

    $file = realpath(__DIR__ . '/../config/common.' . $env . '.json');
    $fh = fopen($file, 'r');
    $config = json_decode(fread($fh, filesize($file)), true);
    fclose($fh);

    $db = null;
    try {
        $db = new PDO(
            'mysql:host=' . $config['database']['host'] . ';dbname=' . $config['database']['dbname'],
            $config['database']['username'],
            $config['database']['password'],
            array(
                PDO::ATTR_PERSISTENT => true,
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET CHARACTER SET `utf8`',
            )
        );
    } catch (PDOException $e) {
        halt("Connection faild: $e");
    }
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    option('db_conn', $db);

    // Memcached
    $m = new Memcached();
    $m->addServer('localhost', 11211);
    option('memcached', $m);
}

function before()
{
    layout('layout.html.php');
    $path = option('base_path');
    if ('/' === $path || preg_match('#^/(?:artist|ticket)#', $path)) {
        $m = option('memcached');
        $order_history = $m->get('order_history');
        if ($order_history === false) {
            $order_history = array();
        }
        set('recent_sold', $order_history);
    }
}

dispatch('/', function () {
    $db = option('db_conn');
    $stmt = $db->query('SELECT * FROM artist ORDER BY id');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    set('artists', $rows);
    return html('index.html.php');
});

dispatch('/artist/:id', function() {
    $db = option('db_conn');

    $stmt = $db->prepare('SELECT id, name FROM artist WHERE id = :id LIMIT 1');
    $stmt->bindValue(':id', params('id'));
    $stmt->execute();
    $artist = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $db->prepare('SELECT id, name FROM ticket WHERE artist_id = :id ORDER BY id');
    $stmt->bindValue(':id', params('id'));
    $stmt->execute();
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sql = <<<SQL
SELECT COUNT(*) FROM variation
INNER JOIN stock ON stock.variation_id = variation.id
WHERE variation.ticket_id = :ticket_id AND stock.order_id IS NULL
SQL;
    $stmt = $db->prepare($sql);
    foreach ($tickets as &$ticket) {
        $stmt->bindValue(':ticket_id', $ticket['id']);
        $stmt->execute();
        $ticket['count'] = $stmt->fetchColumn();
    }

    set('artist', $artist);
    set('tickets', $tickets);
    return html('artist.html.php');
});

dispatch('/ticket/:id', function() {
    $db = option('db_conn');

    $sql = <<<SQL
SELECT t.*, a.name AS artist_name FROM ticket t
INNER JOIN artist a ON t.artist_id = a.id WHERE t.id = :id
LIMIT 1
SQL;
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':id', params('id'));
    $stmt->execute();
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $db->prepare('SELECT id, name FROM variation WHERE ticket_id = :id ORDER BY id');
    $stmt->bindValue(':id', $ticket['id']);
    $stmt->execute();
    $variations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($variations as &$variation) {
        $variation['stock'] = array();
        $stmt = $db->prepare('SELECT seat_id, order_id FROM stock WHERE variation_id = :id');
        $stmt->bindValue(':id', $variation['id']);
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $stock) {
            $variation['stock'][$stock['seat_id']] = $stock;
        }

        $stmt = $db->prepare('SELECT COUNT(*) FROM stock WHERE variation_id = :id AND order_id IS NULL');
        $stmt->bindValue(':id', $variation['id']);
        $stmt->execute();
        $variation['vacancy'] = $stmt->fetchColumn();
    }

    set('ticket', $ticket);
    set('variations', $variations);
    return html('ticket.html.php');
});

dispatch_post('/buy', function() {
    $db = option('db_conn');
    $db->beginTransaction();

    $variation_id = $_POST['variation_id'];
    $member_id = $_POST['member_id'];

    $m = option('memcached');
    if ($m->get('counter') === false) {
        $m->set('counter', 0);
    }
    $order_id = $m->increment('counter', 1);

    $sql = <<<SQL
UPDATE stock SET order_id = :order_id, member_id = :member_id
WHERE variation_id = :variation_id
AND order_id IS NULL
LIMIT 1
SQL;
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':order_id', $order_id);
    $stmt->bindValue(':member_id', $member_id);
    $stmt->bindValue(':variation_id', $variation_id);
    if (false !== $stmt->execute()) {
        $stmt = $db->prepare('SELECT seat_id FROM stock WHERE order_id = :order_id LIMIT 1');
        $stmt->bindValue(':order_id', $order_id);
        $stmt->execute();
        $seat_id = $stmt->fetchColumn();
        
        $sql = <<<SQL
SELECT variation.name AS v_name, ticket.name AS t_name, artist.name AS a_name
FROM variation
JOIN ticket ON variation.ticket_id = ticket.id
JOIN artist ON ticket.artist_id = artist.id
WHERE variation.id = :variation_id
LIMIT 1
SQL;
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':variation_id', $variation_id);
        $stmt->execute();
        $row = $stmt->fetch();
        
        $order_history = $m->get('order_history');
        if ($order_history === false) {
            $order_history = array();
        }
	$order = array(
         'order_id' => $order_id,
         'seat_id' => $seat_id,
         'v_name' => $row['v_name'],
         't_name' => $row['t_name'],
         'a_name' => $row['a_name'],
        );
        $order_history[] = $order;
        usort($order_history, function($a, $b) { return $a['order_id'] > $b['order_id'] ? -1 : 1; });
        $order_history = array_slice($order_history, 0, 10);
        $m->set('order_history', $order_history);

        $db->commit();
        set('member_id', $member_id);
        set('seat_id', $seat_id);
        return html('complete.html.php');
    } else {
        $db->rollback();
        return html('soldout.html.php');
    }
});

dispatch('/admin', function () {
    return html('admin.html.php');
});

dispatch_post('/admin', function () {
    $db = option('db_conn');
    $fh = fopen(realpath(__DIR__ . '/../config/database/initial_data.sql'), 'r');
    while ($sql = fgets($fh)) {
        $sql = rtrim($sql);
        if (!empty($sql)) $db->exec($sql);
    }
    fclose($fh);
    redirect_to('/admin');
});

dispatch('/admin/order.csv', function () {
    $db = option('db_conn');
    $stmt = $db->query(<<<SQL
SELECT order_id, member_id, seat_id, variation_id, updated_at
FROM stock
WHERE order_id IS NOT NULL
ORDER BY order_id ASC
SQL
);
    $body = '';
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($orders as &$order) {
        $body .= join(',', array($order['order_id'], $order['member_id'], $order['seat_id'], $order['variation_id'], $order['updated_at']));
        $body .= "\n";
    }

    send_header('Content-Type: text/csv');
    return $body;
});

run();
