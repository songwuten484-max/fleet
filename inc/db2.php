<?php
// db_connect.php
// ตั้งค่าการเชื่อมต่อฐานข้อมูล
$host = "localhost";       // ชื่อโฮสต์ของ MySQL
$db_name = "fleetdb"; // ชื่อฐานข้อมูล
$username = "roombookingfba";        // ชื่อผู้ใช้ MySQL
$password = "gnH!#987*";            // รหัสผ่าน MySQL

// สร้างการเชื่อมต่อ
$conn = new mysqli($host, $username, $password, $db_name);

// ตรวจสอบการเชื่อมต่อ
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ตั้ง charset ให้ UTF-8
$conn->set_charset("utf8");

// ใช้งาน $conn ในไฟล์อื่น ๆ ได้ทันที

