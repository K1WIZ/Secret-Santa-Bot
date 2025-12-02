<?php
// secret_santa.php
// Main Secret Santa drawing script.
// - Intended to be run from cron on (or around) Thanksgiving at noon.
// - With no arguments, it will *only* run on Thanksgiving Day (4th Thursday in November).
// - When run as: php secret_santa.php -force
//   it will bypass the Thanksgiving check and run immediately.

require __DIR__ . '/vendor/autoload.php';  // PHPMailer via Composer
$config = require __DIR__ . '/config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ---------------------------------------------------------
// CLI argument handling: support "-force"
// ---------------------------------------------------------
$forceRun = false;
if (PHP_SAPI === 'cli') {
    global $argc, $argv;
    if ($argc > 1) {
        foreach ($argv as $arg) {
            if ($arg === '-force' || $arg === '--force') {
                $forceRun = true;
                break;
            }
        }
    }
}

// ---------------------------------------------------------
// Check if today is Thanksgiving (4th Thursday in November)
// ---------------------------------------------------------
function isThanksgivingToday(): bool {
    $today = new DateTime('today', new DateTimeZone('America/New_York'));
    $year  = (int)$today->format('Y');

    $fourthThursday = new DateTime("fourth thursday of november $year", new DateTimeZone('America/New_York'));
    return $today->format('Y-m-d') === $fourthThursday->format('Y-m-d');
}

// If not forced, only run on Thanksgiving
if (!$forceRun && !isThanksgivingToday()) {
    // Not Thanksgiving and not forced, exit quietly.
    exit(0);
}

// ---------------------------------------------------------
// DB connection
// ---------------------------------------------------------
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
    error_log('Secret Santa DB connection failed: ' . $e->getMessage());
    exit(1);
}

// Current year
$year = (int)date('Y');

// ---------------------------------------------------------
// Load participants
// ---------------------------------------------------------
$stmt = $pdo->query("SELECT id, first_name, last_name, email, family_unit FROM participants ORDER BY id ASC");
$participants = $stmt->fetchAll();

if (count($participants) < 2) {
    error_log('Not enough participants to run Secret Santa.');
    exit(1);
}

// Build lookup by id
$participantsById = [];
foreach ($participants as $p) {
    $participantsById[$p['id']] = $p;
}

// ---------------------------------------------------------
// Load last year's pairings (for "no same as last year" rule)
// ---------------------------------------------------------
$lastYear = $year - 1;
$lastYearStmt = $pdo->prepare("SELECT giver_id, receiver_id FROM secret_santa_pairs WHERE year = :year");
$lastYearStmt->execute([':year' => $lastYear]);

$lastYearMap = []; // giver_id => receiver_id
while ($row = $lastYearStmt->fetch()) {
    $lastYearMap[$row['giver_id']] = $row['receiver_id'];
}

// ---------------------------------------------------------
// Build pairings with constraints via backtracking
// ---------------------------------------------------------

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
        // All givers assigned
        return true;
    }

    $giver = $givers[$index];

    // Build list of receiver keys and shuffle for randomness
    $receiverKeys = array_keys($receivers);
    shuffle($receiverKeys);

    foreach ($receiverKeys as $rKey) {
        $receiver = $receivers[$rKey];

        // Constraint 1: cannot give to self
        if ($receiver['id'] == $giver['id']) {
            continue;
        }

        // Constraint 2: cannot give to same family unit
        if ($receiver['family_unit'] == $giver['family_unit']) {
            continue;
        }

        // Constraint 3: must be different from last year's recipient
        if (isset($lastYearMap[$giver['id']]) && $lastYearMap[$giver['id']] == $receiver['id']) {
            continue;
        }

        // Choose this receiver
        $result[$giver['id']] = $receiver;
        $chosen = $receiver;
        unset($receivers[$rKey]);

        // Recurse to next giver
        if (assignPairsRecursive($givers, $receivers, $lastYearMap, $result, $index + 1)) {
            return true;
        }

        // Backtrack
        $receivers[$rKey] = $chosen;
        unset($result[$giver['id']]);
    }

    // No valid assignment for this giver given current history
    return false;
}

// Prepare givers / receivers arrays
$givers    = $participants;
$receivers = [];
foreach ($participants as $p) {
    $receivers[] = $p; // numeric keys okay
}

$resultPairs = []; // giver_id => receiver participant

$success = assignPairsRecursive($givers, $receivers, $lastYearMap, $resultPairs);

if (!$success) {
    // Could not find valid arrangement; notify admin via log (and optionally email)
    error_log("Secret Santa: Failed to find valid pairing for year $year. Check constraints/family units.");
    exit(1);
}

// ---------------------------------------------------------
// Save new pairings to DB
//   (We assume one set of pairings per year. If you want to re-run,
//    you can either delete existing rows for $year first, or treat
//    this as the authoritative run. Here we delete any existing
//    rows for safety.)
//
//   If you *never* re-run for the same year in production, you can remove
//   the DELETE and rely on INSERT only.
// ---------------------------------------------------------
try {
    $pdo->beginTransaction();

    $del = $pdo->prepare("DELETE FROM secret_santa_pairs WHERE year = :year");
    $del->execute([':year' => $year]);

    $insert = $pdo->prepare("
        INSERT INTO secret_santa_pairs (year, giver_id, receiver_id)
        VALUES (:year, :giver_id, :receiver_id)
    ");

    foreach ($resultPairs as $giverId => $receiver) {
        $insert->execute([
            ':year'       => $year,
            ':giver_id'   => $giverId,
            ':receiver_id'=> $receiver['id'],
        ]);
    }

    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("Secret Santa: Failed to save pairings for year $year: " . $e->getMessage());
    exit(1);
}

// ---------------------------------------------------------
// Setup mailer
// ---------------------------------------------------------
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

$mailer = createMailer($config['smtp']);

// ---------------------------------------------------------
// Build & send individual emails
// ---------------------------------------------------------
function buildRecipientEmailHtml(array $giver, array $receiver, int $year): string
{
    $giverName    = htmlspecialchars($giver['first_name'] . ' ' . $giver['last_name']);
    $receiverName = htmlspecialchars($receiver['first_name'] . ' ' . $receiver['last_name']);

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Secret Santa $year</title>
</head>
<body style="margin:0;padding:0;background:#0b1b33;font-family:Arial,Helvetica,sans-serif;">
  <div style="max-width:600px;margin:0 auto;padding:20px;">
    <div style="background:linear-gradient(135deg,#b30000,#006600);border-radius:12px;padding:20px;text-align:center;color:#ffffff;">
      <h1 style="font-size:32px;margin:0 0 10px;font-weight:bold;letter-spacing:1px;">
        🎄 Secret Santa $year 🎄
      </h1>
      <p style="font-size:16px;margin:10px 0 20px;">
        Hi <strong>{$giverName}</strong>!<br>
        The elves have spoken...
      </p>
      <div style="background:#ffffff;border-radius:10px;padding:30px;margin:0 auto;max-width:480px;">
        <p style="font-size:14px;color:#444;margin:0 0 10px;">
          Your Secret Santa person is:
        </p>
        <div style="font-size:30px;font-weight:bold;color:#b30000;margin:10px 0 10px;">
          {$receiverName}
        </div>
        <p style="font-size:13px;color:#666;margin:10px 0 0;">
          🎁 Please keep this a secret and bring some holiday cheer to your person! 🎁
        </p>
      </div>
      <p style="font-size:11px;color:#f0f0f0;margin-top:15px;">
        (This is an automated Secret Santa drawing. If you think something is wrong, contact the organizer.)
      </p>
    </div>
  </div>
</body>
</html>
HTML;
}

$masterListRows = [];
$adminEmail     = 'john@wizworks.net';

foreach ($participants as $giver) {
    $giverId  = $giver['id'];
    $receiver = $resultPairs[$giverId];

    $toEmail = $giver['email'];
    if (empty($toEmail)) {
        error_log("Secret Santa: Participant ID {$giverId} has no email, skipping.");
        continue;
    }

    $htmlBody = buildRecipientEmailHtml($giver, $receiver, $year);
    $subject  = "Your Secret Santa Person for $year 🎄";

    try {
        $mail = clone $mailer; // fresh instance per send
        $mail->clearAllRecipients();
        $mail->addAddress($toEmail, $giver['first_name'] . ' ' . $giver['last_name']);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = "Hi {$giver['first_name']}, your Secret Santa person for $year is {$receiver['first_name']} {$receiver['last_name']}.";

        $mail->send();
    } catch (Exception $e) {
        error_log("Secret Santa: Failed to send email to {$toEmail}: " . $e->getMessage());
    }

    // Build master list row
    $masterListRows[] = [
        'giver'    => $giver['first_name'] . ' ' . $giver['last_name'],
        'receiver' => $receiver['first_name'] . ' ' . $receiver['last_name'],
    ];
}

// ---------------------------------------------------------
// Send master list to admin
// ---------------------------------------------------------
$rowsHtml = '';
foreach ($masterListRows as $row) {
    $g = htmlspecialchars($row['giver']);
    $r = htmlspecialchars($row['receiver']);
    $rowsHtml .= "<tr><td style=\"padding:6px 10px;border:1px solid #ddd;\">{$g}</td><td style=\"padding:6px 10px;border:1px solid #ddd;\">{$r}</td></tr>";
}

$masterHtml = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Secret Santa Master List $year</title>
</head>
<body style="font-family:Arial,Helvetica,sans-serif;background:#f5f5f5;padding:20px;">
  <h2>Secret Santa Master List - {$year}</h2>
  <table style="border-collapse:collapse;background:#ffffff;">
    <thead>
      <tr style="background:#006600;color:#ffffff;">
        <th style="padding:8px 10px;border:1px solid #ddd;">Giver</th>
        <th style="padding:8px 10px;border:1px solid #ddd;">Receiver</th>
      </tr>
    </thead>
    <tbody>
      {$rowsHtml}
    </tbody>
  </table>
</body>
</html>
HTML;

try {
    $mail = clone $mailer;
    $mail->clearAllRecipients();
    $mail->addAddress($adminEmail, 'Secret Santa Admin');
    $mail->Subject = "Secret Santa Pairings for $year";
    $mail->Body    = $masterHtml;
    $mail->AltBody = "Secret Santa $year master list:\n\n" .
        implode("\n", array_map(
            fn($row) => "{$row['giver']} -> {$row['receiver']}",
            $masterListRows
        ));

    $mail->send();
} catch (Exception $e) {
    error_log("Secret Santa: Failed to send master list: " . $e->getMessage());
}

exit(0);
