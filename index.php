<?php 

$err_org_name = false;
$err_code = false;
$war_no_repos = false;

// sorts repositories by column
function sorted_repos($repos, $col, $asc) {
  $column = array_column($repos, $col);
  $sort_by = array_map('strtolower', $column);
  if ($asc) {
    array_multisort($sort_by, SORT_ASC, $repos);
  } else {
    array_multisort($sort_by, SORT_DESC, $repos);
  }
  return $repos;
}

// serializes repositories in order to insert them into an html form
function serialized_repos($repos) {
  $serialized = "";
  $i = 0;
  foreach ($repos as $r) {
    $serialized = 
      $serialized .
      '<input type="text" name="passed_repos[' . $i . '][name]" value="' . $r['name'] . '" hidden>' .
      '<input type="text" name="passed_repos[' . $i . '][contribs]" value="' . $r['contribs'] . '" hidden>' .
      '<input type="text" name="passed_repos[' . $i . '][parent]" value="' . $r['parent'] . '" hidden>' .
      '<input type="text" name="passed_repos[' . $i . '][html_url]" value="' . $r['html_url'] . '" hidden>';
    $i++;
  }
  return $serialized;
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

if (isset($_GET['auth_token'])) {
  $auth_token = $_GET['auth_token'];
}

// do when received a request for organisation's repositories
if (check_org()) {
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

  // configures passed curl handler
  function config_curl(&$ch, $url, $opts, &$headers) {
    curl_setopt_array($ch, $opts);
    curl_setopt($ch, CURLOPT_URL, $url);

    // mapping headers into an associative array
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, 
      function ($curl, $header) use (&$headers) {
        $len = strlen($header);
        $header = explode(':', $header, 2);
        if (count($header) < 2) return $len;
        $headers[strtolower(trim($header[0]))] = trim($header[1]);
        return $len;
      }
    );
  }

  // sends a single request to URL
  // returns response headers(as an array) and body(as a string)
  function fetch_data(&$ch, $url, $opts) {
    
    $headers = [];
    config_curl($ch, $url, $opts, $headers);
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
    $mh = curl_multi_init();

    // adding curl handles to multi_handle
    $chs = ['parents' => [], 'contribs' => []];
    for ($i = 0; $i < count($repos); $i++) {
      if ($repos[$i]['fork']) {
        $ch_p = curl_init();
        array_push(
          $chs['parents'], 
          ['headers' => [], 'body' => $ch_p]
        );
        config_curl(
          $ch_p, 
          $repos[$i]['url'], 
          $options, 
          $chs['parents'][$i]['headers']
        );
        curl_multi_add_handle($mh, $ch_p);
      } else {
        array_push($chs['parents'], false);
      }

      $ch_c = curl_init();
      array_push(
        $chs['contribs'], 
        ['headers' => [], 'body' => $ch_c]
      );
      config_curl(
        $ch_c, 
        $repos[$i]['contributors_url'] . '?per_page=1&anon=true', 
        $options, 
        $chs['contribs'][$i]['headers']
      );
      curl_multi_add_handle($mh, $ch_c);
    }

    // executing multi_handle
    $running = null;
    do {
      curl_multi_exec($mh, $running);
    } while ($running);

    // adding fetched data to repositories and closing curl handles
    for ($i = 0; $i < count($repos); $i++) {
      $parent = $chs['parents'][$i];
      $contribs = $chs['contribs'][$i];
      if ($parent) {
        curl_multi_remove_handle($mh, $parent['body']);
        $parent_body = curl_multi_getcontent($parent['body']);
        $parent_json = json_decode($parent_body, true)['parent'];
        $repos[$i]['parent'] = $parent_json['html_url'];
      } else {
        $repos[$i]['parent'] = false;
      }

      curl_multi_remove_handle($mh, $contribs['body']);
      $repos[$i]['contribs'] =
        (array_key_exists("link", $contribs['headers'])) ? 
        pages_count($contribs['headers']['link']) : 0;
    }

    curl_multi_close($mh);

    $repos = sorted_repos($repos, "name", true);
    $sm = "n-asc";

  } else {
    if (gettype($repos_res) == "integer") { $err_code = $repos_res; }
    if (empty($repos_res)) { $war_no_repos = true; }
  }
  
  curl_close($ch);
}

// do when received a request for sorting repositories
if (isset($_GET['sort_mode'])) {
  $org = $_GET['org_passed'];
  $sm = $_GET['sort_mode'];
  $pr = $_GET['passed_repos'];
  switch ($sm) {
    case "n-asc":
      $repos = sorted_repos($pr, 'name', true);
      break;
    case "n-desc":
      $repos = sorted_repos($pr, 'name', false);
      break;
    case "c-asc":
      $repos = sorted_repos($pr, 'contribs', true);
      break;
    case "c-desc":
      $repos = sorted_repos($pr, 'contribs', false);
      break;
  }
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
  $org = "---";
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
    Wytyczne:
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
    <input 
      type="text" 
      name="auth_token" 
      class="form-control" 
      value="<?php echo isset($auth_token) ? $auth_token : ""; ?>"
    >
  </div>
  <div class="mb-3">
    <label class="form-label">Nazwa organizacji</label>
    <input type="text" name="org" class="form-control" placeholder="madkom">
  </div>
  <button
    id="btn-get-repos"
    onclick="
      const spin = document.getElementById('spinner');
      const btnTxt = document.getElementById('btn-txt');
      const btnGetRepos = document.getElementById('btn-get-repos');
      btnGetRepos.disabled = true;
      btnTxt.style.display = 'none';
      spin.style.display = 'block';" 
    type="submit" 
    class="btn btn-primary my-3">
    <span id="spinner" style="display: none;" class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
    <span id="btn-txt">Pokaż repozytoria</span>
  </button>
</form>

<div class="mb-4">
  Repozytoria organizacji:
  <?php 
    echo " " . (isset($org) ? $org: "---");
  ?>
</div>

<?php 
if (isset($repos)) { 
  $s_repos = serialized_repos($repos);
?>
  <form action="<?php htmlspecialchars($_SERVER["PHP_SELF"]); ?> " method="get" style="width: 300px;">
  <input type="text" name="org_passed" value="<?php echo $org; ?>" hidden>
    <?php echo $s_repos; ?>
    <input 
      type="text" 
      name="auth_token" 
      class="form-control" 
      value="<?php echo isset($auth_token) ? $auth_token : ""; ?>"
      hidden
    >
    <select name="sort_mode" class="form-select" onchange="this.form.submit()">
      <option <?php echo ($sm == "n-asc") ? "selected" : "" ?> value="n-asc">Nazwa rosnąco</option>
      <option <?php echo ($sm == "n-desc") ? "selected" : "" ?> value="n-desc">Nazwa malejąco</option>
      <option <?php echo ($sm == "c-asc") ? "selected" : "" ?> value="c-asc">Kontrybutorzy rosnąco</option>
      <option <?php echo ($sm == "c-desc") ? "selected" : "" ?> value="c-desc">Kontrybutorzy malejąco</option>
    </select>
  </form>
<?php }?>

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
