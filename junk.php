<?php

// dist weds: 11, 06, 05, 06
// dist all : 07, 08, 10, 09

// years since
// 0 === 5
// 1 === 6[1]
// 2 === 6[0]
// 3 === 11

// switch to post input later
$input = $_GET['birthday'] ?? null;
$nowInput = $_GET['now'] ?? (new DateTimeImmutable('now'))->format('Y-m-d');
$allowSubmit = !($_GET['hide'] ?? null);

function validateDate($date, $format = 'Y-m-d')
{
    $d = DateTimeImmutable::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

if (!validateDate($input)) {
    $input = '1980-01-01';
}

if (!validateDate($nowInput)) {
    $nowInput = (new DateTimeImmutable('now'))->format('Y-m-d');
}

?>
<style>
    body {
        font-family: Arial, sans-serif;
        margin: 20px;
    }

    .widget {
        border: 1px solid #ccc;
        border-radius: 8px;
        padding: 20px;
        max-width: 400px;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
    }

    .widget h2 {
        margin-top: 0;
    }

    .widget table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 20px;
    }

    .widget table, .widget th, .widget td {
        border: 1px solid #ddd;
    }

    .widget th, .widget td {
        padding: 8px;
        text-align: left;
    }

    .widget th {
        background-color: #f2f2f2;
    }

    .progress-bar-container {
        position: relative;
        height: 30px;
        background-color: #f3f3f3;
        border-radius: 5px;
        overflow: hidden;
        margin-bottom: 20px;
    }

    .progress-bar {
        height: 100%;
        background-color: #4caf50;
        width: 0;
        text-align: center;
        line-height: 30px;
        color: black;
        font-weight: bold;
        transition: width 0.4s ease;
    }
</style>
<?php

if ($allowSubmit) {
    ?>
<script src="https://unpkg.com/htmx.org@1.9.12"></script>
<div id="birthday">
    <form hx-get="<?= $_SERVER['REQUEST_URI'] ?>?hide=1" hx-swap="outerHTML" hx-target=".widget">
        <label for="birthday">Birthday</label>
        <input id="birthday" name="birthday" type="date" value="<?= $input ?>">
        <br>
        <label for="now">Today</label>
        <input id="now" name="now" type="date" value="<?= $nowInput ?>">
        <button type="submit">
            Update
        </button>
    </form>
    <?php

        if (null === $input) {
            echo '</div>';
            die();
        }
} else {
    echo '<div id="birthday">';
}

$now = new DateTimeImmutable($nowInput);
$currentYear = $now->format('Y');

$o = $birthday = new DateTimeImmutable($input);
$birthdayday = $birthday->format('l');

if ($now->format('m') <= $birthday->format('m') && $now->format('d') < $birthday->format('d')) {
    // we are not on the current year
    $currentYear -= 1;
}

$weekdays = [
    'Monday' => null,
    'Tuesday' => null,
    'Wednesday' => null,
    'Thursday' => null,
    'Friday' => null,
    'Saturday' => null,
    'Sunday' => null,
];
$day = 'null';
$expectancy = 80;

keep_going:
$years = null;
$startYear = $birthday->format('Y');

for ($i = 0; $i < $expectancy; $i++) {
    $day = $birthday->format('l');
    $year = $birthday->format('Y');
    $weekdays[$day] ??= $year;

    if ($i > 0 && $day === $o->format('l')) {
        $years ??= $birthday->diff($o)->y;
    }

    if ('02-29' === $birthday->format('m-d')) {
        $birthday = $birthday->add(new DateInterval('P4Y'));
    } else {
        $birthday = $birthday->add(new DateInterval('P1Y'));
    }
}

$next_cycle = $o->add(new DateInterval('P' . $years . 'Y'))->format('Y');

$missing = array_filter(array_map(fn($x) => $x < $next_cycle ? null : $x, $weekdays));
foreach ($missing as $day => $_) {
    unset($weekdays[$day]);
}
asort($weekdays);

$data = [
    'day' => $birthdayday,
    'weekdays' => $weekdays,
    'missing' => $missing,
    'years' => $next_cycle - $startYear,
    'time to fill' => max($weekdays) - min($weekdays),
    'next cycled' => $next_cycle,
    'cycle start' => $lastData['next cycled'] ?? $o->format('Y'),
];

$data['percentage complete'] =
    number_format(($currentYear - $data['cycle start']) / ($data['years'] ?: 1) * 100, 1);

if ($data['next cycled'] <= $currentYear) {
    $lastData = $data;
    $weekdays = [
        'Monday' => null,
        'Tuesday' => null,
        'Wednesday' => null,
        'Thursday' => null,
        'Friday' => null,
        'Saturday' => null,
        'Sunday' => null,
    ];
    $birthday = new DateTimeImmutable($data['next cycled'] . $birthday->format('-m-d'));
    goto keep_going;
}

?>
    <div class="widget">
        <h2>Birthday Cycle Information</h2>
        <table>
            <tr>
                <th>Birthday Day</th>
                <td><?= $data['day'] ?></td>
            </tr>
            <tr>
                <th>Years to Complete Cycle</th>
                <td><?= $data['years'] ?></td>
            </tr>
            <tr>
                <th>Next Cycle Year</th>
                <td><?= $data['next cycled'] ?></td>
            </tr>
            <tr>
                <th>Cycle Start Year</th>
                <td><?= $data['cycle start'] ?></td>
            </tr>
            <tr>
                <th>Percentage Complete</th>
                <td>
                    <div class="progress-bar-container">
                        <div style="width:<?= $data['percentage complete'] ?>%" class="progress-bar"
                             id="progressBar"><?= $data['percentage complete'] ?>%
                        </div>
                    </div>
                </td>
            </tr>
        </table>
        <h3>Weekday Distribution</h3>
        <table>
            <thead>
            <tr>
                <th>Weekday</th>
                <th>Year</th>
            </tr>
            </thead>
            <tbody>
            <?php
        foreach ($data['weekdays'] as $day => $year): ?>
                <tr>
                    <td><?= $day ?></td>
                    <td><?= $year ?? 'N/A' ?></td>
                </tr>
            <?php
        endforeach; ?>
            </tbody>
        </table>
        <h3>Missing Weekdays From Cycle</h3>
        <table>
            <thead>
            <tr>
                <th>Weekday</th>
                <th>Year</th>
            </tr>
            </thead>
            <tbody>
            <?php
        foreach ($data['missing'] as $day => $year): ?>
                <tr>
                    <td><?= $day ?></td>
                    <td><?= $year ?? 'N/A' ?></td>
                </tr>
            <?php
        endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    function sendHeight() {
        const height = document.body.scrollHeight;
        window.parent.postMessage(height, '*');
    }

    window.onload = function () {
        const progressBar = document.getElementById('progressBar');
        const percentageComplete = <?= $data['percentage complete'] ?>;
        progressBar.style.width = percentageComplete + '%';
        sendHeight();
    };

    window.onresize = sendHeight;
</script>
