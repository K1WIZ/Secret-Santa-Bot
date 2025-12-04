<?php
// wishes.php
// Participant wishlist portal:
// - Auth: via ?key=wish_key in URL
// - Shows recipient's wishes for the current year
// - Allows user to edit their own 3-item wishlist
// - When the wishlist is saved, notifies their Secret Santa (giver) by email.

require __DIR__ . '/vendor/autoload.php';
$config = require __DIR__ . '/config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

try {
    $pdo = new PDO(
        $config['db']['dsn'],
        $config['db']['user'],
        $config['db']['password'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo "Database connection failed.";
    exit;
}

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function createMailer(array $smtpConfig): PHPMailer
{
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $smtpConfig['host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $smtpConfig['username'];
    $mail->Password   = $smtpConfig['password'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = $smtpConfig['port'];

    $mail->setFrom($smtpConfig['from_email'], $smtpConfig['from_name']);
    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';

    return $mail;
}

// -------------------------------------------
// Simple key auth via ?key=... (wish_key)
// -------------------------------------------
$key = $_GET['key'] ?? '';

if ($key === '') {
    http_response_code(403);
    echo "Missing key.";
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, first_name, last_name, email, family_unit,
           wish_item1, wish_item2, wish_item3, wish_key
    FROM participants
    WHERE wish_key = :key
");
$stmt->execute([':key' => $key]);
$me = $stmt->fetch();

if (!$me) {
    http_response_code(403);
    echo "Invalid key.";
    exit;
}

$year = (int)date('Y');
$savedMessage = null;

// -------------------------------------------
// Handle wishlist form submission
// -------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $w1 = trim($_POST['wish_item1'] ?? '');
    $w2 = trim($_POST['wish_item2'] ?? '');
    $w3 = trim($_POST['wish_item3'] ?? '');

    $upd = $pdo->prepare("
        UPDATE participants
        SET wish_item1 = :w1,
            wish_item2 = :w2,
            wish_item3 = :w3
        WHERE id = :id
    ");
    $upd->execute([
        ':w1' => $w1 !== '' ? $w1 : null,
        ':w2' => $w2 !== '' ? $w2 : null,
        ':w3' => $w3 !== '' ? $w3 : null,
        ':id' => $me['id'],
    ]);

    // Refresh $me data
    $stmt->execute([':key' => $key]);
    $me = $stmt->fetch();

    $savedMessage = "Your wishlist has been saved. 🎁";

    // -------------------------------------------
    // Notify this person's Secret Santa (their giver)
    // -------------------------------------------
    try {
        $pairStmt = $pdo->prepare("
            SELECT giver_id
            FROM secret_santa_pairs
            WHERE year = :year AND receiver_id = :receiver_id
            LIMIT 1
        ");
        $pairStmt->execute([
            ':year'        => $year,
            ':receiver_id' => $me['id'],
        ]);
        $pair = $pairStmt->fetch();

        if ($pair) {
            // Load giver info
            $giverStmt = $pdo->prepare("
                SELECT id, first_name, last_name, email, wish_key
                FROM participants
                WHERE id = :id
            ");
            $giverStmt->execute([':id' => $pair['giver_id']]);
            $giver = $giverStmt->fetch();

            if ($giver && !empty($giver['email']) && !empty($giver['wish_key'])) {
                $mailer  = createMailer($config['smtp']);
                $baseUrl = rtrim($config['app']['base_url'] ?? '', '/');

                $giverName     = $giver['first_name'] . ' ' . $giver['last_name'];
                $receiverName  = $me['first_name'] . ' ' . $me['last_name'];
                $giverWishUrl  = $baseUrl . '/wishes.php?key=' . urlencode($giver['wish_key']) . '#recipient';

                $subject = "New Wish List Update from {$receiverName} 🎄";

                $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Wish List Update - Secret Santa {$year}</title>
</head>
<body style="margin:0;padding:0;background:#0b1b33;font-family:Arial,Helvetica,sans-serif;">
  <div style="max-width:600px;margin:0 auto;padding:20px;">
    <div style="background:linear-gradient(135deg,#b30000,#006600);border-radius:12px;padding:20px;text-align:center;color:#ffffff;">
      <h1 style="font-size:28px;margin:0 0 10px;font-weight:bold;letter-spacing:1px;">
        🎁 Secret Santa Wish List Update 🎁
      </h1>
      <p style="font-size:15px;margin:10px 0 20px;">
        Hi <strong>{$giverName}</strong>!<br>
        Your Secret Santa recipient <strong>{$receiverName}</strong> just updated their wish list.
      </p>
      <div style="background:#ffffff;border-radius:10px;padding:22px;margin:0 auto;max-width:480px;">
        <p style="font-size:14px;color:#444;margin:0 0 10px;">
          You can view their latest wishes here:
        </p>
        <p style="margin:12px 0;">
          <a href="{$giverWishUrl}"
             style="display:inline-block;background:#b30000;color:#ffffff;padding:10px 18px;border-radius:6px;
             text-decoration:none;font-weight:bold;font-size:14px;">
            View {$receiverName}'s Wish List
          </a>
        </p>
        <p style="font-size:12px;color:#666;margin-top:14px;">
          Checking their wish list is a great way to avoid accidentally gifting them
          their 7th pair of Christmas socks. Unless that's the plan. 🧦
        </p>
      </div>
      <p style="font-size:11px;color:#f0f0f0;margin-top:15px;">
        This message was sent automatically when your recipient updated their wish list
        on the Secret Santa site.
      </p>
    </div>
  </div>
</body>
</html>
HTML;

                $altBody = "Hi {$giverName},\n\n"
                    . "Your Secret Santa recipient {$receiverName} just updated their wish list.\n\n"
                    . "You can view it here:\n{$giverWishUrl}\n\n"
                    . "Happy gifting! 🎄";

                try {
                    $mail = clone $mailer;
                    $mail->clearAllRecipients();
                    $mail->addAddress($giver['email'], $giverName);
                    $mail->Subject = $subject;
                    $mail->Body    = $htmlBody;
                    $mail->AltBody = $altBody;
                    $mail->send();

                    $savedMessage = "Your wishlist has been saved, and your Secret Santa has been notified. 🎁";
                } catch (Exception $e) {
                    // If mail send fails, keep original "saved" message
                    error_log("Secret Santa wishes: Failed to send wishlist update email to {$giver['email']}: " . $e->getMessage());
                }
            }
        }
    } catch (PDOException $e) {
        // Log but don't break page
        error_log("Secret Santa wishes: Error while notifying Secret Santa: " . $e->getMessage());
    }
}

// -------------------------------------------
// Find this person's recipient for current year
// -------------------------------------------
$recipient = null;
$pairStmt = $pdo->prepare("
    SELECT receiver_id
    FROM secret_santa_pairs
    WHERE year = :year AND giver_id = :giver_id
");
$pairStmt->execute([
    ':year'    => $year,
    ':giver_id'=> $me['id'],
]);
$pair = $pairStmt->fetch();

if ($pair) {
    $recStmt = $pdo->prepare("
        SELECT id, first_name, last_name,
               wish_item1, wish_item2, wish_item3
        FROM participants
        WHERE id = :id
    ");
    $recStmt->execute([':id' => $pair['receiver_id']]);
    $recipient = $recStmt->fetch();
}

$myName  = $me['first_name'] . ' ' . $me['last_name'];
$recName = $recipient ? ($recipient['first_name'] . ' ' . $recipient['last_name']) : null;

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Secret Santa Wishes</title>
<style>
    body {
        font-family: Arial, Helvetica, sans-serif;
        background: radial-gradient(circle at top, #234 0, #020915 60%, #01030a 100%);
        color: #f5f5f5;
        margin: 0;
        padding: 0;
    }
    .container {
        max-width: 900px;
        margin: 30px auto;
        background: rgba(10, 19, 40, 0.95);
        border-radius: 14px;
        padding: 20px 25px 30px;
        box-shadow: 0 0 20px rgba(0,0,0,0.7);
        border: 1px solid rgba(255,255,255,0.05);
    }
    h1 {
        text-align: center;
        margin-top: 0;
        margin-bottom: 5px;
        color: #ffd700;
        text-shadow: 0 0 8px rgba(255,215,0,0.5);
    }
    .subtitle {
        text-align: center;
        margin-bottom: 20px;
        color: #d0e0ff;
    }
    .section {
        margin-top: 20px;
        padding: 15px 18px;
        border-radius: 10px;
        background: linear-gradient(135deg, rgba(179,0,0,0.9), rgba(0,102,0,0.9));
    }
    .section-inner {
        background: rgba(0,0,0,0.3);
        border-radius: 8px;
        padding: 12px 14px 16px;
    }
    h2 {
        margin-top: 0;
        color: #ffe9a4;
        font-size: 20px;
    }
    h3 {
        margin: 8px 0;
        font-size: 16px;
        color: #fffde7;
    }
    ul {
        padding-left: 20px;
        margin: 5px 0 0;
    }
    li {
        margin: 3px 0;
    }
    .empty-note {
        font-size: 13px;
        color: #ffefef;
        font-style: italic;
    }
    label {
        display: block;
        margin: 8px 0 4px;
        font-size: 14px;
    }
    input[type="text"] {
        width: 100%;
        padding: 6px 8px;
        border-radius: 4px;
        border: 1px solid #ddd;
        font-size: 14px;
    }
    input[type="text"]:focus {
        outline: none;
        border-color: #ffd700;
        box-shadow: 0 0 4px rgba(255,215,0,0.6);
    }
    button {
        margin-top: 12px;
        background: linear-gradient(135deg, #ffd700, #ff6600);
        border: none;
        color: #202020;
        padding: 8px 16px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
        font-weight: bold;
    }
    button:hover {
        opacity: 0.92;
    }
    .saved-msg {
        margin-top: 8px;
        font-size: 13px;
        color: #c8ffd5;
    }
    .name-tag {
        font-weight: bold;
        color: #fff;
    }
    .section-anchor {
        position: relative;
        top: -80px;
    }
    .small-note {
        font-size: 11px;
        color: #cbd5ff;
        margin-top: 12px;
        text-align: center;
    }
</style>
</head>
<body>
<div class="container">
    <h1>🎄 Secret Santa Wishes 🎄</h1>
    <div class="subtitle">
        Hi <span class="name-tag"><?php echo h($myName); ?></span>!  
        Here you can see your recipient's wish list and set your own (up to 3 items).
    </div>

    <div class="section" id="recipient">
        <div class="section-inner">
            <h2>Your Recipient's Wishes</h2>
            <?php if ($recipient): ?>
                <h3>For: <?php echo h($recName); ?></h3>
                <?php
                    $r1 = $recipient['wish_item1'] ?? '';
                    $r2 = $recipient['wish_item2'] ?? '';
                    $r3 = $recipient['wish_item3'] ?? '';
                    $hasAny = ($r1 || $r2 || $r3);
                ?>
                <?php if ($hasAny): ?>
                    <ul>
                        <?php if ($r1): ?><li><?php echo h($r1); ?></li><?php endif; ?>
                        <?php if ($r2): ?><li><?php echo h($r2); ?></li><?php endif; ?>
                        <?php if ($r3): ?><li><?php echo h($r3); ?></li><?php endif; ?>
                    </ul>
                <?php else: ?>
                    <p class="empty-note">
                        Your recipient hasn't shared any wishes yet.  
                        Time to get creative… or interrogate them casually. 😉
                    </p>
                <?php endif; ?>
            <?php else: ?>
                <p class="empty-note">
                    It looks like we don't have a Secret Santa pairing for you yet for <?php echo h($year); ?>.
                    Check back later once the drawing is complete.
                </p>
            <?php endif; ?>
        </div>
    </div>

    <div class="section" id="mine" style="margin-top:25px;">
        <div class="section-inner">
            <h2>Your Wish List</h2>
            <form method="post">
                <label for="wish_item1">Wish Item #1</label>
                <input type="text" name="wish_item1" id="wish_item1"
                       value="<?php echo h($me['wish_item1'] ?? ''); ?>">

                <label for="wish_item2">Wish Item #2</label>
                <input type="text" name="wish_item2" id="wish_item2"
                       value="<?php echo h($me['wish_item2'] ?? ''); ?>">

                <label for="wish_item3">Wish Item #3</label>
                <input type="text" name="wish_item3" id="wish_item3"
                       value="<?php echo h($me['wish_item3'] ?? ''); ?>">

                <button type="submit">Save My Wishes</button>

                <?php if (!empty($savedMessage)): ?>
                    <div class="saved-msg"><?php echo h($savedMessage); ?></div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="small-note">
        Your wish list is only visible to your Secret Santa (and the admin, who is pretending not to peek).
    </div>
</div>
</body>
</html>
