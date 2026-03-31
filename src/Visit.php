<?php declare(strict_types=1);

/*
 * Copyright (c) Igara Studio S.A.
 *
 * This software may be modified and distributed under the terms
 * of the MIT license. See the LICENSE file for details.
 */

namespace IgaraStudio;

use function PHPUnit\Framework\assertTrue;
use function PHPUnit\Framework\assertEquals;

// Simulates website requests using php-cgi, without running
// a webserver. Support cookies/sessions to emulate an user
// login and keep state between requests.
class Visit
{
  public static array $shared_options = [ 'docroot' => './public',
                                          'script' => './public/index.php',
                                          'followRedirect' => true ];

  private array $options;
  private string $method;
  private string $path;
  private string $stdout;
  private string $stderr;
  private string $body;
  private string $redir_location = '';
  private int $status_code = 0;
  private array $cookies = [];
  private array $mocks = [];

  public function __construct(array $options = []) {
    if (!empty($options))
      $this->options = array_merge(self::$shared_options, $options);
    else
      $this->options = self::$shared_options;
  }

  public function setOptions(array $options):Visit {
    $this->options = array_merge(self::$shared_options, $options);
    return $this;
  }

  public function get(string $path):Visit {
    return $this->simulateRequest("GET", $path);
  }

  public function post(string $path, array|string $data, string $content_type = 'application/x-www-form-urlencoded'):Visit {
    if (is_array($data)) {
      switch($content_type) {
        case "application/x-www-form-urlencoded":
          $data = http_build_query($data);
          break;
        case "application/json":
          $data = json_encode($data);
          break;
      }
    }
    return $this->simulateRequest("POST", $path, $data, $content_type);
  }

  public function postJson(string $path, array|string $data):Visit {
    return $this->post($path, $data, "application/json");
  }

  public function assertStatusCode(int $expected_code):Visit {
    assertEquals($expected_code, $this->status_code,
                 "$this->method $this->path : Didn't return $expected_code status code");
    return $this;
  }

  public function assertSee(string $expected_str):Visit {
    assertTrue(str_contains($this->body, $expected_str),
               "$this->method $this->path : String '$expected_str' not found in output\n\n::group::Body:\n$this->body\n::endgroup::");
    return $this;
  }

  public function assertDontSee(string $unexpected_str):Visit {
    assertTrue(!str_contains($this->body, $unexpected_str),
               "$this->method $this->path : String '$unexpected_str' was found in output\n\n::group::Body:\n$this->body\n::endgroup::");
    return $this;
  }

  public function assertRedirect(string $expected_path):Visit {
    $this->assertStatusCode(302);
    assertEquals($expected_path, $this->redir_location,
                 "$this->method $this->path : Didn't redirect to $expected_path");
    return $this;
  }

  public function assertStderrContains(string $expected_str):Visit {
    assertTrue(str_contains($this->stderr, $expected_str),
               "$this->method $this->path : String '$expected_str' not found in STDERR\n\n::group::Content:\n$this->stderr\n::endgroup::");
    return $this;
  }

  public function mock($subject, string $callable):Visit {
    if (empty($this->options['patchwork'])) {
      throw new \Exception("Cannot use mocks, option 'patchwork' (path to Patchwork.php) was not provided");
    }

    $this->mocks[$subject] = $callable;
    return $this;
  }

  public function unmock($subject):Visit {
    unset($this->mocks[$subject]);
    return $this;
  }

  // Useful when followRedirect=false, so we have a function to go the
  // redirected location. When followRedirect=true the redirection is
  // automatic.
  public function followRedirect():Visit {
    return $this->simulateRequest("GET", $this->redir_location);
  }

  private function simulateRequest(string $method,
                                   string $path,
                                   ?string $data = null,
                                   string $content_type = 'application/x-www-form-urlencoded'):Visit {
    $this->method = $method;
    $this->path = $path;

    $script = $this->options['script'] ?? '.';
    $docroot = $this->options['docroot'] ?? '.';

    if (!file_exists($script))
      die("File $script doesn't exist to be called with php-cgi\n");

    if (count($this->mocks))
      $script = $this->generateMockedScript($script);

    $old_redir_location = $this->redir_location;
    $this->status_code = 0;
    $this->redir_location = '';

    $env = array_merge(getenv(),
                       ['REQUEST_METHOD' => $method,
                        'REQUEST_SCHEME' => 'http',
                        'HTTP_HOST' => 'localhost',
                        'REQUEST_URI' => $path,
                        'QUERY_STRING' => parse_url($path, PHP_URL_QUERY),
                        'SCRIPT_NAME' => basename($script),
                        'SCRIPT_FILENAME' => $script,
                        'CONTENT_LENGTH' => ($data ? strlen($data): 0),
                        'CONTENT_TYPE' => $content_type,
                        'HTTP_COOKIE' => (!empty($this->cookies) ? implode('; ', $this->cookies) . ';': '')]);

    $desc = [["pipe", "r"],
             ["pipe", "w"],
             ["pipe", "w"]];

    $f = proc_open("php-cgi" .
                   " -d variables_order=EGPCS" .
                   " -d log_errors=On" .
                   " -d doc_root=$docroot" .
                   " -d cgi.force_redirect=0" .
                   " -d session.save_path=/tmp" .
                   " -d opcache.jit=disable " .
                   " -d cgi.nph=1" .
                   $script,
                   $desc, $pipes, env_vars:$env);

    if ($data !== null) {
      fwrite($pipes[0], $data);
    }
    fclose($pipes[0]);

    $this->stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $this->stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $status = proc_close($f);
    assertEquals(0, $status,
                 "$this->method $this->path : Procedure to handle request failed with status $status.\nOutput: $this->stdout\nError: $this->stderr");

    if (count($this->mocks))
      $this->removeMockedScript($script);

    [$headers, $this->body] = explode("\r\n\r\n", $this->stdout, 2);
    foreach (explode("\r\n", $headers) as $header) {
      if (str_starts_with($header, 'Status: ')) {
        $status_line = substr($header, 8);
      }
      else if (str_starts_with($header, 'Location: ')) {
        $this->redir_location = substr($header, 10);
      }
      else if (str_starts_with($header, 'Set-Cookie: ')) {
        $cookie = substr($header, 12, strpos($header, ';')-12);
        array_push($this->cookies, $cookie);
      }
    }
    if (isset($status_line)) {
      $this->status_code = intval($status_line);
      if ($this->status_code == 302 && $old_redir_location == $this->redir_location) {
        throw new \Exception("Infinite redirection to $this->redir_location");
      }
    }

    // Auto-follow 302 redirections
    if (!empty($this->redir_location) && ($this->options['followRedirect'] ?? true))
      $this->followRedirect();

    return $this;
  }

  private function generateMockedScript($script):string {
      $mocksCode = "";
      foreach ($this->mocks as $subject => $callableStr) {
        $mocksCode .= "redefine('$subject', $callableStr);" . PHP_EOL;
      }

      $pathToPatchwork = $this->options['patchwork'];
      $mockedScript = pathinfo($script, PATHINFO_DIRNAME) . DIRECTORY_SEPARATOR . "_mocked_" . basename($script);
      file_put_contents($mockedScript, <<<EOD
        <?php
        require_once "$pathToPatchwork";

        use function Patchwork\{redefine};

        $mocksCode

        include "$script";
        ?>
      EOD);

      return $mockedScript;
  }

  private function removeMockedScript($script) {
    unlink($script);
  }

}
