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

if (isset($_GET['auth_token']) && check_org()) {
  $auth_token = $_GET['auth_token'];

  $headers = [
    "Authorization: Token $auth_token",
    "User-Agent: PHP"
  ];
    
  $options = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => "GET",
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_CAINFO => "cacert.pem"
  ];

  // sends a single request to URL
  // returns response headers(as an array) and body(as a string)
  function fetch_data(&$ch, $url, $opts) {
    curl_setopt_array($ch, $opts);
    curl_setopt($ch, CURLOPT_URL, $url);

    // mapping headers into an associative array
    $headers = [];
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, 
      function ($curl, $header) use (&$headers) {
        $len = strlen($header);
        $header = explode(':', $header, 2);
        if (count($header) < 2) return $len;
        $headers[strtolower(trim($header[0]))] = trim($header[1]);
        return $len;
      }
    );

    $body = curl_exec($ch);
    $res_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($res_code >= 400) {
      return $res_code;
    }
    return ["headers" => $headers, "body" => $body];
  }

  $ch = curl_init();
  var_dump(fetch_data(
    $ch, 
    "https://api.github.com/orgs/$org/repos", 
    $options
  ));
  curl_close($ch);
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
