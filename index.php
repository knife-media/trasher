<?php
/**
 * Trash handler
 * Simple moderation admin page to remove comments with offensive language
 *
 * @author Anton Lukin
 * @license MIT
 * @version 1.0
 */


require_once(__DIR__ . '/vendor/autoload.php');

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();


function connect_database() {
    $dsn = "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']};charset=utf8mb4";

    // Set PDO options
    $settings = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => true
    ];

    $database = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASSWORD'], $settings);

    $query = "CREATE TABLE IF NOT EXISTS trasher (
        id int NOT NULL AUTO_INCREMENT,
        comment_id int NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY comment_id (comment_id))";

    // Try to create table
    $database->exec($query);

    return $database;
}


function select_comments($offensive = []) {
    $database = connect_database();

    $query = "SELECT comments.id, comments.parent, comments.post_id, comments.content, comments.created
        FROM comments
        LEFT JOIN trasher ON trasher.comment_id = comments.id
        WHERE comments.status = 'visible' AND trasher.comment_id IS NULL
        ORDER BY created DESC";

    // Get all visible comments
    $select = $database->query($query);

    foreach ($select->fetchAll() as $comment) {
        $badwords = Censure\Censure::parse($comment['content']);

        if ($badwords) {
            $offensive[] = array_merge($comment, ['badwords' => $badwords]);
        }

        if (count($offensive) >= 50) {
            break;
        }
    }

    return $offensive;
}


function remove_comment($id) {
    try {
        $database = connect_database();

        $update = $database->prepare("UPDATE comments SET status = 'removed' WHERE id = :id");
        $update->execute(compact('id'));

        $insert = $database->prepare("INSERT IGNORE INTO trasher (comment_id) VALUES (:id)");
        $insert->execute(compact('id'));

    } catch(Exception $e) {
        echo json_encode(['success' => false]);
        exit;
    }
}


function approve_comment($id) {
    try {
        $database = connect_database();

        $insert = $database->prepare("INSERT IGNORE INTO trasher (comment_id) VALUES (:id)");
        $insert->execute(compact('id'));

    } catch(Exception $e) {
        echo json_encode(['success' => false]);
        exit;
    }
}


function cancel_comment($id) {
    try {
        $database = connect_database();

        $update = $database->prepare("UPDATE comments SET status = 'visible' WHERE id = :id");
        $update->execute(compact('id'));

        $insert = $database->prepare("DELETE FROM trasher WHERE comment_id = :id");
        $insert->execute(compact('id'));

    } catch(Exception $e) {
        echo json_encode(['success' => false]);
        exit;
    }
}


function route_requests($body) {
    if (!isset($body->status, $body->id)) {
        return;
    }

    header('Content-Type: application/json');

    if ($body->status === 'remove') {
        remove_comment($body->id);
    }

    if ($body->status === 'approve') {
        approve_comment($body->id);
    }

    if ($body->status === 'cancel') {
        cancel_comment($body->id);
    }

    echo json_encode(['success' => true]);
    exit;
}

$json = file_get_contents('php://input');

if (!empty($json)) {
    route_requests(json_decode($json, false));
}

$offensive = select_comments();

?><!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8" />
    <meta name="robots" content="follow, index">
    <meta name="description" content="">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/styles.css">

    <title>Модерация матерных комментариев на Ноже</title>

    <style>
        body {
            margin: 0;
            padding: 1rem;
            font: normal 16px/1.5 -apple-system, "Segoe UI", Roboto, Ubuntu, "Helvetica Neue", sans-serif;
        }

        a {
            color: #26a8ed;
            text-decoration: none;

            border-bottom: solid 1px;
        }

        a:hover {
            border-bottom-color: transparent;
        }

        h1 {
            margin-bottom: 2.5rem;
            font-size: 1.5rem;
            font-weight: 400;
        }

        h1 span {
            display: block;
            color: #999;
            font-size: 0.875rem;
        }

        .content {
            display: block;
            position: relative;

            width: 60rem;
            max-width: 100%;
            margin: 2rem auto;
        }

        .item {
            display: block;

            margin: 1rem 0;
            padding: 1rem;

            background-color: #f4f4f4;
            border: solid 1px #eee;
            border-radius: 0.25rem;
        }

        .item h4 {
            margin: 0;
            font-weight: normal;
            font-size: 0.875rem;
        }

        .item h4 span {
            color: #777;
        }

        .item h5 {
            margin: 0;
            font-size: 0.875rem;
            color: #777;
        }

        .item h5 span {
            font-weight: normal;
        }

        .item-cancel p,
        .item-cancel h5,
        .item-cancel button {
           display: none;
        }

        .item-cancel .button-cancel {
            display: block;
        }

        .manage {
            display: flex;
        }

        button {
            display: block;
            cursor: pointer;

            padding: 0.5rem 1.5rem;
            margin-top: 1.5rem;
            margin-right: 1rem;

            background: #ddd;
            color: #777;

            border: solid 1px #999;
            border-radius: 0.25rem;
        }

        .button-cancel {
            display: none;
        }

        .button-approve {
            background: #ada;
            color: #151;

            border: solid 1px #9c9;
        }

        .button-remove {
            background: #daa;
            color: #733;

            border: solid 1px #c99;
        }
    </style>
</head>

<body>
    <section class="content">
        <h1>Модерация матерных комментариев на Ноже <span>Отображаются последние 50 комментариев</span></h1>

        <?php foreach($offensive as $item) : ?>
            <article class="item" data-id="<?= $item['id'] ?>">
                <h4>
                    <?php
                        printf(
                            '<a href="https://knife.media/?p=%2$d#comment-%1$d" target="_blank">#%1$d</a>',
                            $item['id'], $item['post_id']
                        );
                    ?>

                    <?php
                        if ($item['parent']) {
                            printf(
                                '<span>в ответ на </span><a href="https://knife.media/?p=%2$d#comment-%1$d" target="_blank">#%1$d</a>',
                                $item['parent'], $item['post_id']
                            );
                        }
                    ?>
                </h4>

                <?php
                    printf(
                        '<p>%s</p>', nl2br(trim($item['content']))
                    );

                    printf(
                        '<h5>Мотив: <span>%s</span></h5>', $item['badwords']
                    );
                ?>

                <div class="manage">
                    <button class="button button-approve" data-status="approve">Оставить</button>
                    <button class="button button-remove" data-status="remove">Удалить</button>
                    <button class="button button-cancel" data-status="cancel">Отменить изменения</button>
                </div>
            </article>
        <?php endforeach; ?>
    </section>

    <script>
        function sendRequest(item, data) {
            var request = new XMLHttpRequest();
            request.open('POST', document.location);
            request.setRequestHeader('Content-Type', 'application/json');
            request.send(JSON.stringify(data));

            request.onload = function () {
                if (request.status === 200) {
                    return item.classList.toggle('item-cancel');
                }

                alert('Произошла ошибка. Не удалось отправить запрос');
            }

            request.onerror = function() {
                alert('Произошла ошибка. Не удалось отправить запрос');
            }
        }


        var buttons = document.querySelectorAll('.manage .button');

        for (var i = 0; i < buttons.length; i++) {
            buttons[i].addEventListener('click', function(e) {
                e.preventDefault();

                var item = this.parentNode.parentNode;

                var data = {
                    status: this.getAttribute('data-status'),
                    id: item.getAttribute('data-id')
                }

                sendRequest(item, data);
            });
        }
    </script>
</body>
</html>
