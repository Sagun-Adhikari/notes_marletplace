<?php
session_start();
include "config/db.php";

$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $password = $_POST['password'];

    $result = mysqli_query($conn, "SELECT * FROM users WHERE username='$username'");
    if(mysqli_num_rows($result) > 0){
        $user = mysqli_fetch_assoc($result);
        if(password_verify($password, $user['password'])){
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            header("Location: index.php");
            exit();
        } else {
            $message = "Incorrect password ❌";
        }
    } else {
        $message = "Username not found ❌";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Login - Notes Site</title>
    <!-- Add this in your <head> -->
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
<style>
  /* ---- Global Styles ---- */
  body {
    font-family: 'Roboto', sans-serif;
    background: linear-gradient(120deg, #f0f4ff, #e8f0fe);
    margin: 0;
    padding: 0;
    overflow-x: hidden;
    transition: background 1s ease;
  }

  a {
    text-decoration: none;
    color: inherit;
    transition: color 0.3s ease;
  }

  /* ---- Header ---- */
  header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 50px;
    background: #ffffffdd;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    position: sticky;
    top: 0;
    z-index: 1000;
    backdrop-filter: blur(10px);
    animation: slideDown 1s ease forwards;
  }

  @keyframes slideDown {
    from { transform: translateY(-100px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
  }

  /* ---- Buttons ---- */
  .btn {
    padding: 12px 24px;
    background: #4f46e5;
    color: #fff;
    border-radius: 8px;
    font-weight: 500;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
  }

  .btn:hover {
    background: #4338ca;
    transform: scale(1.05);
    box-shadow: 0 8px 20px rgba(0,0,0,0.15);
  }

  /* ---- Notes Grid ---- */
  .notes-container {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px;
    padding: 40px 50px;
  }

  .note-card {
    background: #fff;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 6px 18px rgba(0,0,0,0.08);
    transition: all 0.3s ease;
    transform: translateY(30px);
    opacity: 0;
    animation: fadeUp 0.8s forwards;
  }

  @keyframes fadeUp {
    to { transform: translateY(0); opacity: 1; }
  }

  .note-card:hover {
    transform: translateY(-10px) scale(1.02);
    box-shadow: 0 12px 28px rgba(0,0,0,0.12);
  }

  .note-title {
    font-size: 18px;
    font-weight: 600;
    margin-bottom: 10px;
  }

  .note-desc {
    font-size: 14px;
    color: #555;
    margin-bottom: 15px;
  }

  .note-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
  }

  /* ---- Popup Upload Form ---- */
  .popup {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.6);
    display: flex;
    justify-content: center;
    align-items: center;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.3s ease;
  }

  .popup.active {
    opacity: 1;
    pointer-events: auto;
  }

  .popup-content {
    background: #fff;
    border-radius: 12px;
    padding: 30px 40px;
    width: 400px;
    max-width: 90%;
    text-align: center;
    transform: scale(0.7);
    transition: transform 0.3s ease;
  }

  .popup.active .popup-content {
    transform: scale(1);
  }

  /* ---- Animations on Scroll ---- */
  .fade-in {
    opacity: 0;
    transform: translateY(20px);
    transition: all 0.6s ease-out;
  }

  .fade-in.show {
    opacity: 1;
    transform: translateY(0);
  }

</style>

    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<div class="container">
    <h2>Login</h2>

    <?php if($message): ?>
        <p class="error"><?php echo $message; ?></p>
    <?php endif; ?>

    <form method="POST">
        <input type="text" name="username" placeholder="Username" required>
        <input type="password" name="password" placeholder="Password" required>
        <input type="submit" value="Login">
    </form>

    <p>Don't have an account? <a href="signup.php">Sign Up here</a></p>
</div>

</body>
</html>
