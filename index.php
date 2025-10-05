<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Switter</title>
    <link rel="shortcut icon" href="elements/media/images/icons.png" />
</head>
<body>
    <?php
session_start();
include "elements/php/header.php";
?>

<div class="flex w-full max-w-7xl mx-auto min-h-screen">
    <?php include "elements/php/sidebar-left.php"; ?>
    <?php include 'feed.php'; ?>
    <?php include "elements/php/sidebar-right.php"; ?>
    <?php include "elements/php/modal.php"; ?>
</div>

<?php
include "elements/php/footer.php";
?>
</body>
</html>