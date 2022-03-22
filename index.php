<?php 

$err_org_name = false;

// checks if the organization name is passed and correct
function check_org() {
  global $err_org_name;
  if (isset($_GET['org'])) {
    global $org;
    $org = $_GET['org'];
    if (
      (strlen($org) > 0) && 
      (strlen($org) < 40) &&
      (substr($org, 0, 1) != "-") &&
      !str_contains($org, "--") &&
      (preg_replace("/[a-zA-Z0-9-]+/", "", $org) == "")
    ) {
      return true;
    } else {
      $org = "---";
      $err_org_name = true;
      return false;
    }
  } else {
    return false;
  }
}

echo check_org() ? $org : "ERROR";

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
