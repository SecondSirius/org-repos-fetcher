<?php 

$err_org_name = false;
$err_code = false;
$war_no_repos = false;

// sorts repositories by column
function sorted_repos($repos, $col, $asc) {
  // $sort_by = array_column($repos, $col);
  $column = array_column($repos, $col);
  $sort_by = array_map('strtolower', $column);
  if ($asc) {
    array_multisort($sort_by, SORT_ASC, $repos);
  } else {
    array_multisort($sort_by, SORT_DESC, $repos);
  }
  return $repos;
}

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
    return ['headers' => $headers, 'body' => $body];
  }

  // merges two string response bodies into a single one
  // in order to call json_decode only once
  function merge_bodies($body_1, $body_2) {
    if ($body_1 == "") {
      return $body_2;
    } else {
      return
        substr($body_1, 0, -1) .
        ", " .
        substr($body_2, 1);
    }
  }

  // extracts number of pages from the response link
  function pages_count($res_link) {
    preg_match(
      '/[0-9]+>; rel="last"/', 
      $res_link, 
      $matches, 
      PREG_OFFSET_CAPTURE
    );
    $pages_count = explode(">", $matches[0][0])[0];
    return intval($pages_count);
  }

  function fetch_all_pages(&$ch, $url, $opts) {
    $page = 1;
    $collected_data = "";

    while (true) {
      $res = 
        fetch_data($ch, $url . "?per_page=100&page=$page", $opts);
      if (gettype($res) == "integer") {
        return $res;
      } elseif (!empty($res['body']) && $res['body'] !== "[]") {
        $collected_data = merge_bodies($collected_data, $res['body']);
      } else {
        return $collected_data;
      }
      $page++;
    }
  }

  // fetching respositories of the chosen organisation
  $ch = curl_init();
  $repos_res = fetch_all_pages(
    $ch, 
    "https://api.github.com/orgs/$org/repos", 
    $options
  );

  // do only if repos were fetched correctly
  if (gettype($repos_res) != "integer" && !empty($repos_res)) {
    $repos = json_decode($repos_res, true);

    // fetching forks' parents
    for ($i = 0; $i < count($repos); $i++) {
      if ($repos[$i]['fork']) {
        $parent_res_body = 
          fetch_data($ch, $repos[$i]['url'], $options)['body'];
        $parent = json_decode($parent_res_body, true);
        $repos[$i]['parent'] = $parent['parent']['html_url'];
      } else {
        $repos[$i]['parent'] = false;
      }
    }

    // fetching repositories' contributor numbers
    for ($i = 0; $i < count($repos); $i++) {
      $res = fetch_data(
        $ch, 
        $repos[$i]['contributors_url'] . "?per_page=1&anon=true",
        $options
      );

      $repos[$i]['contribs'] = 
        (array_key_exists("link", $res['headers'])) ? 
        pages_count($res['headers']['link']) : 0;
    }

    $repos = sorted_repos($repos, "name", true);

  } else {
    if (gettype($repos_res) == "integer") { $err_code = $repos_res; }
    if (empty($repos_res)) { $war_no_repos = true; }
  }
  
  curl_close($ch);
}

?>


<html>
<link 
  href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" 
  rel="stylesheet" 
  integrity="sha384-1BmE4kWBq78iYhFldvKuhfTAU6auU8tT94WrHftjDbrCEXSU1oBoqyl2QvZ6jIW3" 
  crossorigin="anonymous"
>
<body style="max-width: 1000px;" class="bg-dark text-light fs-2 m-5">

<?php 
if ($err_code == 404) {
  echo '
  <div class="alert alert-danger" role="alert">
    Nie znaleziono organizacji "' . $org . '"
  </div>
  ';
} else if ($err_code == 401) {
  echo '
  <div class="alert alert-danger" role="alert">
    Użytkownik niezautoryzowany. <br>
    Upewnij się, że token jest poprawny.
  </div>
  ';
} else if ($err_code) {
  echo '
  <div class="alert alert-danger" role="alert">
    Wystąpił błąd. Kod: ' . $err_other . '
  </div>
  ';
}

if ($war_no_repos) {
  echo '
  <div class="alert alert-warning" role="alert">
    Organizacja "' . $org . '" nie posiada repozytoriów.
  </div>
  ';
}

if ($err_org_name) {
  echo '
  <div class="alert alert-danger" role="alert">
    Niepoprawna nazwa organizacji. <br>
    Nazwa organizacji:
    <ul>
      <li>może składać się maksymalnie z 39 znaków</li>
      <li>może zawierać tylko znaki alfanumeryczne i "-"</li>
      <li>nie może zaczynać się od "-"</li>
      <li>nie może zawierać "--"</li>
    </ul>  
  </div>
  ';
}
?>

<form 
  action="<?php htmlspecialchars($_SERVER["PHP_SELF"]); ?>" 
  method="get" 
  style="width: 600px;" 
  class="p-3 mb-5"
>
  <div class="mb-3">
    <label class="form-label">Token autoryzacyjny</label>
    <input type="text" name="auth_token" class="form-control">
  </div>
  <div class="mb-3">
    <label class="form-label">Nazwa organizacji</label>
    <input type="text" name="org" class="form-control" placeholder="madkom">
  </div>
  <button type="submit" class="btn btn-primary my-3">Pokaż repozytoria</button>
</form>

<div class="mb-4">
  Repozytoria organizacji:
  <?php 
    echo " " . (isset($org) ? $org: "---");
  ?>
</div>

<table class="table table-dark table-striped">
  <thead>
    <tr>
      <th scope="col">#</th>
      <th scope="col">Nazwa</th>
      <th scope="col">Kontrybutorzy</th>
      <th scope="col">Repozytorium źródłowe</th>
    </tr>
  </thead>
  <?php
    if (isset($repos)) {
      $i = 1;
      foreach ($repos as $r) {
        echo '
          <tr>
            <th scope="row">' . $i . '</th>
            <td><a href="' . $r['html_url'] . '">' . $r["name"] . '</a></td>
            <td>' . $r["contribs"] . '</td>
            <td><a href="' . $r['parent'] . '">' . $r["parent"] . '</a></td>
          </tr>
        ';
        $i++;
      }
    } else {
      echo '
        <tr>
          <th scope="row">---</th>
          <td>---</td>
          <td>---</td>
          <td>---</td>
        </tr>
      ';
    }
  ?>
</table>

</body>
</html>
