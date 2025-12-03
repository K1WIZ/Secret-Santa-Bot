<?php
// admin_secret_santa.php
// Admin/testing page for Secret Santa pairing.
// - Can run in TEST mode (no DB writes) or COMMIT mode (writes to DB for selected year).
// - Can send a test email using the configured SMTP/Gmail settings.
// - Shows a table of all participants and their wish lists.

require __DIR__ . '/vendor/autoload.php'; // PHPMailer via Composer
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
    die('DB connection failed: ' . htmlspecialchars($e->getMessage()));
}

// -----------------------------------------------------------------
// Minimal "auth" – change this to something safer or integrate with real auth
// Example usage: /admin_secret_santa.php?key=changeme
// -----------------------------------------------------------------
$ADMIN_KEY = 'changeme';
if (!isset($_GET['key']) || $_GET['key'] !== $ADMIN_KEY) {
    http_response_code(403);
    echo "Forbidden. Admin key missing or incorrect.";
    exit;
}

// ------------------------
// Helper: recursive pairing
// ------------------------
/**
 * Recursive backtracking assignment.
 *
 * @param array $givers           List of participants (array of assoc arrays)
 * @param array $receivers        Remaining receivers (id => participant array)
 * @param array $lastYearMap      giver_id => receiver_id from last year
 * @param array $result           giver_id => receiver participant
 * @param int   $index            index into $givers
 * @return bool
 */
function assignPairsRecursive(array $givers, array &$receivers, array $lastYearMap, array &$result, int $index = 0): bool
{
    if ($index >= count($givers)) {
        return true; // all done
    }

    $giver = $givers[$index];

    $receiverKeys = array_keys($receivers);
    shuffle($receiverKeys);

    foreach ($receiverKeys as $rKey) {
        $receiver = $receivers[$rKey];

        // 1) Cannot give to self
        if ($receiver['id'] === $giver['id']) {
            continue;
        }

        // 2) Cannot give to same family unit
        if ($receiver['family_unit'] === $giver['family_unit']) {
            continue;
        }

        // 3) Cannot give to same person as last year (if present in map)
        if (isset($lastYearMap[$giver['id']]) && $lastYearMap[$giver['id']] === $receiver['id']) {
            continue;
        }

        // Choose this receiver
        $result[$giver['id']] = $receiver;
        $chosen = $receiver;
        unset($receivers[$rKey]);

        if (assignPairsRecursive($givers, $receivers, $lastYearMap, $result, $index + 1)) {
            return true;
        }

        // Backtrack
        $receivers[$rKey] = $chosen;
        unset($result[$giver['id']]);
    }

    return false; // no valid choice for this giver
}

// ------------------------
// Helper: create PHPMailer instance
// ------------------------
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

// ------------------------
// Fetch participants (include wishlist fields)
// ------------------------
$stmt = $pdo->query("
    SELECT
        id, first_name, last_name, email, family_unit,
        wish_item1, wish_item2, wish_item3, wish_key
    FROM participants
    ORDER BY id ASC
");
$participants = $stmt->fetchAll();

if (count($participants) < 2) {
    $error = 'Not enough participants in the database.';
}

// ------------------------
// Handle form submit
// ------------------------
$generatedPairs = [];
$debugMessage   = '';
$error          = $error ?? null;

$defaultYear = (int)date('Y');
$year        = isset($_POST['year']) ? (int)$_POST['year'] : $defaultYear;
$avoidSameAsLastYear = !empty($_POST['avoid_last_year']);
$commitToDb          = !empty($_POST['commit_to_db']);
$action              = $_POST['action'] ?? 'generate';

// Default test email address: SMTP from_email if set
$defaultTestEmail = $config['smtp']['from_email'] ?? '';
$testEmail        = $_POST['test_email'] ?? $defaultTestEmail;

// Utility for HTML escaping
function h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

// ------------------------
// ACTION: Send test email
// ------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'test_email') {
    if (empty($testEmail)) {
        $error = 'Please specify a test email address.';
    } else {
        try {
            $mailer = createMailer($GLOBALS['config']['smtp']);

            $mail = clone $mailer;
            $mail->clearAllRecipients();
            $mail->addAddress($testEmail);

            $mail->Subject = 'Secret Santa SMTP Test 🎄';
            $yearNow = date('Y');

            $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Secret Santa SMTP Test</title>
</head>
<body style="margin:0;padding:0;background:#0b1b33;font-family:Arial,Helvetica,sans-serif;">
  <div style="max-width:600px;margin:0 auto;padding:20px;">
    <div style="background:linear-gradient(135deg,#b30000,#006600);border-radius:12px;padding:20px;text-align:center;color:#ffffff;">
      <h1 style="font-size:32px;margin:0 0 10px;font-weight:bold;letter-spacing:1px;">
        🎄 Secret Santa SMTP Test 🎄
      </h1>
      <p style="font-size:16px;margin:10px 0 20px;">
        This is a <strong>test email</strong> from your Secret Santa application for {$yearNow}.
      </p>
      <div style="background:#ffffff;border-radius:10px;padding:20px;margin:0 auto;max-width:480px;">
        <p style="font-size:14px;color:#444;margin:0 0 10px;">
          If you can read this, your Gmail/SMTP configuration is working!
        </p>
        <p style="font-size:13px;color:#666;margin:10px 0 0;">
          When the real drawing runs, participants will receive similarly styled emails
          with the name of their Secret Santa recipient in a big festive font.
        </p>
      </div>
      <p style="font-size:11px;color:#f0f0f0;margin-top:15px;">
        (You triggered this test from the Secret Santa admin page.)
      </p>
    </div>
  </div>
</body>
</html>
HTML;

            $mail->Body    = $htmlBody;
            $mail->AltBody = "Secret Santa SMTP Test.\n\nIf you received this, SMTP is working.";

            $mail->send();
            $debugMessage = 'Test email sent successfully to <strong>' . h($testEmail) . '</strong>.';
        } catch (Exception $e) {
            $error = 'Failed to send test email: ' . h($e->getMessage());
        }
    }
}

// ------------------------
// ACTION: Generate (and optionally commit) pairings
// ------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'generate' && empty($error)) {

    // Build giver/receiver arrays
    $givers    = $participants;
    $receivers = [];
    foreach ($participants as $p) {
        $receivers[] = $p;
    }

    // Load last year's pairs if requested
    $lastYearMap = [];
    if ($avoidSameAsLastYear && $year > 0) {
        $lastYear = $year - 1;
        $stmtLY = $pdo->prepare("SELECT giver_id, receiver_id FROM secret_santa_pairs WHERE year = :year");
        $stmtLY->execute([':year' => $lastYear]);

        while ($row = $stmtLY->fetch()) {
            $lastYearMap[$row['giver_id']] = $row['receiver_id'];
        }
    }

    $resultPairs = []; // giver_id => receiver participant
    $success = assignPairsRecursive($givers, $receivers, $lastYearMap, $resultPairs);

    if ($success) {
        // Build display-friendly structure
        $generatedPairs = [];
        foreach ($participants as $giver) {
            $giverId  = $giver['id'];
            $receiver = $resultPairs[$giverId];
            $generatedPairs[] = [
                'giver_id'        => $giverId,
                'giver_name'      => $giver['first_name'] . ' ' . $giver['last_name'],
                'giver_family'    => $giver['family_unit'],
                'receiver_id'     => $receiver['id'],
                'receiver_name'   => $receiver['first_name'] . ' ' . $receiver['last_name'],
                'receiver_family' => $receiver['family_unit'],
                'same_as_last'    => (isset($lastYearMap[$giverId]) && $lastYearMap[$giverId] === $receiver['id']),
            ];
        }

        // If commit option is ON, write to DB for the selected year
        if ($commitToDb) {
            try {
                $pdo->beginTransaction();

                // Remove any existing pairings for that year (so you can re-test)
                $del = $pdo->prepare("DELETE FROM secret_santa_pairs WHERE year = :year");
                $del->execute([':year' => $year]);

                $ins = $pdo->prepare("
                    INSERT INTO secret_santa_pairs (year, giver_id, receiver_id)
                    VALUES (:year, :giver_id, :receiver_id)
                ");

                foreach ($resultPairs as $giverId => $receiver) {
                    $ins->execute([
                        ':year'       => $year,
                        ':giver_id'   => $giverId,
                        ':receiver_id'=> $receiver['id'],
                    ]);
                }

                $pdo->commit();
                $debugMessage = "Pairing generated and <strong>saved to DB</strong> for year {$year}. "
                    . "You can now test 'avoid last year' using year " . ($year + 1) . ".";
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = 'Failed to save pairing to DB: ' . h($e->getMessage());
            }
        } else {
            $debugMessage = 'Pairing generated in <strong>TEST MODE</strong>. '
                . 'No DB writes or emails were performed.';
        }
    } else {
        $error = 'Failed to find a valid pairing with the current constraints. '
               . 'Try relaxing the "avoid last year" option or double-check family units.';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Secret Santa Admin</title>
<style>
    body {
        font-family: Arial, Helvetica, sans-serif;
        background: #0b1b33;
        color: #f5f5f5;
        margin: 0;
        padding: 0;
    }
    .container {
        max-width: 900px;
        margin: 30px auto;
        background: #1b2b4d;
        border-radius: 12px;
        padding: 20px 25px 30px;
        box-shadow: 0 0 12px rgba(0,0,0,0.5);
    }
    h1 {
        text-align: center;
        margin-top: 0;
        margin-bottom: 10px;
        color: #ffd700;
    }
    .subtitle {
        text-align: center;
        margin-bottom: 25px;
        color: #d0e0ff;
    }
    form {
        margin-bottom: 25px;
        background: #12203b;
        border-radius: 10px;
        padding: 15px 20px;
    }
    label {
        display: inline-block;
        margin: 5px 0;
    }
    input[type="number"],
    input[type="email"],
    input[type="text"] {
        padding: 5px 8px;
        border-radius: 4px;
        border: 1px solid #4a6bb8;
        background: #0b1427;
        color: #f5f5f5;
    }
    input[type="number"] {
        width: 100px;
    }
    input[type="email"] {
        width: 260px;
    }
    input[type="checkbox"] {
        transform: scale(1.1);
        margin-right: 5px;
    }
    button {
        background: linear-gradient(135deg, #b30000, #006600);
        border: none;
        color: #fff;
        padding: 8px 16px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
        margin-top: 10px;
        margin-right: 8px;
    }
    button:hover {
        opacity: 0.9;
    }
    .msg {
        margin-bottom: 15px;
        padding: 10px 12px;
        border-radius: 8px;
        font-size: 14px;
    }
    .msg.error {
        background: #661212;
        color: #ffd0d0;
    }
    .msg.ok {
        background: #124b2a;
        color: #d0ffd8;
    }
    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
    }
    th, td {
        padding: 6px 8px;
        border: 1px solid #32456f;
        font-size: 13px;
        vertical-align: top;
    }
    th {
        background: #243a6f;
    }
    tr:nth-child(even) {
        background: #19274a;
    }
    tr:nth-child(odd) {
        background: #111b34;
    }
    .badge {
        display: inline-block;
        padding: 2px 6px;
        border-radius: 4px;
        font-size: 11px;
    }
    .badge-ok {
        background: #185c2f;
        color: #c8ffd5;
    }
    .badge-warn {
        background: #7a261e;
        color: #ffe0d0;
    }
    .footer-note {
        margin-top: 15px;
        font-size: 11px;
        color: #9fb5ff;
    }
    .warning-text {
        font-size: 12px;
        color: #ffdddd;
        margin-top: 5px;
    }
    .section-title {
        font-weight: bold;
        margin-top: 5px;
        margin-bottom: 5px;
        color: #ffe9a4;
    }
    .section-divider {
        border-top: 1px solid #32456f;
        margin: 15px 0;
    }
    h2 {
        margin-top: 25px;
        margin-bottom: 8px;
        color: #ffd700;
    }
</style>
</head>
<body>
<div class="container">
    <h1>🎄 Secret Santa Admin 🎄</h1>
    <div class="subtitle">
        Generate Secret Santa pairings, commit them to the database, test SMTP email delivery,<br>
        and view everyone’s wish lists in one place.
    </div>

    <?php if (!empty($error)): ?>
        <div class="msg error"><?php echo $error; ?></div>
    <?php elseif (!empty($debugMessage)): ?>
        <div class="msg ok"><?php echo $debugMessage; ?></div>
    <?php endif; ?>

    <form method="post">
        <div class="section-title">Pairing Options</div>

        <div>
            <label for="year">Target year:&nbsp;</label>
            <input type="number" name="year" id="year" value="<?php echo h($year); ?>">
        </div>
        <div style="margin-top:10px;">
            <label>
                <input type="checkbox" name="avoid_last_year" <?php echo $avoidSameAsLastYear ? 'checked' : ''; ?>>
                Avoid assigning the same recipient as last year (if last year&rsquo;s data exists)
            </label>
        </div>
        <div style="margin-top:10px;">
            <label>
                <input type="checkbox" name="commit_to_db" <?php echo $commitToDb ? 'checked' : ''; ?>>
                <strong>Commit this pairing to the database for the selected year</strong>
            </label>
            <div class="warning-text">
                Warning: This will <strong>delete any existing pairings</strong> for that year
                from <code>secret_santa_pairs</code> and replace them with this result.
            </div>
        </div>

        <div class="section-divider"></div>

        <div class="section-title">Email Test</div>
        <div>
            <label for="test_email">Send a test email to:&nbsp;</label>
            <input type="email" name="test_email" id="test_email"
                   value="<?php echo h($testEmail); ?>">
        </div>
        <div class="warning-text">
            This sends a <strong>single test email</strong> using your Gmail/SMTP config.
            It does not send any Secret Santa assignments.
        </div>

        <div style="margin-top:15px;">
            <button type="submit" name="action" value="generate">Generate Pairings</button>
            <button type="submit" name="action" value="test_email">Send Test Email</button>
        </div>
    </form>

    <?php if (!empty($generatedPairs)): ?>
        <h2>Generated Pairings for <?php echo h($year); ?></h2>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Giver</th>
                    <th>Giver Family</th>
                    <th>Receiver</th>
                    <th>Receiver Family</th>
                    <th>Same as Last Year?</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($generatedPairs as $idx => $row): ?>
                <tr>
                    <td><?php echo h($idx + 1); ?></td>
                    <td><?php echo h($row['giver_name']); ?></td>
                    <td><?php echo h($row['giver_family']); ?></td>
                    <td><?php echo h($row['receiver_name']); ?></td>
                    <td><?php echo h($row['receiver_family']); ?></td>
                    <td>
                        <?php if ($row['same_as_last']): ?>
                            <span class="badge badge-warn">YES</span>
                        <?php else: ?>
                            <span class="badge badge-ok">No</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="footer-note">
            To test the "avoid last year" logic end-to-end, you can:
            <ol>
                <li>Generate &amp; <strong>commit</strong> pairings for year <code>X</code>.</li>
                <li>Then, generate pairings for year <code>X+1</code> with
                    "avoid last year" checked and verify no giver has the same recipient twice.</li>
            </ol>
        </div>
    <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'generate' && empty($error)): ?>
        <div class="footer-note">No pairings were generated.</div>
    <?php endif; ?>

    <!-- Wishes table -->
    <h2>All Participants & Wish Lists</h2>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Name</th>
                <th>Email</th>
                <th>Family Unit</th>
                <th>Wish #1</th>
                <th>Wish #2</th>
                <th>Wish #3</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($participants as $idx => $p): ?>
            <tr>
                <td><?php echo h($idx + 1); ?></td>
                <td><?php echo h($p['first_name'] . ' ' . $p['last_name']); ?></td>
                <td><?php echo h($p['email']); ?></td>
                <td><?php echo h($p['family_unit']); ?></td>
                <td><?php echo h($p['wish_item1'] ?? ''); ?></td>
                <td><?php echo h($p['wish_item2'] ?? ''); ?></td>
                <td><?php echo h($p['wish_item3'] ?? ''); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="footer-note">
        This table reflects the latest wishes saved via <code>wishes.php</code>.
        Great for sanity-checking who wants what before the big reveal.
    </div>
</div>
</body>
</html>
