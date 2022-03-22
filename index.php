<?php 

  if (isset($_GET['auth_token']) && isset($_GET['org'])) {
    echo "Repozytoria organizacji: " . $_GET['org'];
  }

?>


<html>
<body>

<form action="<?php htmlspecialchars($_SERVER["PHP_SELF"]);?> " method="get">
  <input type="text" name="auth_token" placeholder="token">
  <input type="text" name="org" placeholder="organizacja">
  <button type="submit">Pokaż repozytoria</button>
</form>

</body>
</html>
