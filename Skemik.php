<?php

/* Reads a Skemik install's feed from a site on ordinary webspace, and writes to it: PHP 8.0 or newer
   with curl, no library and no build step. Copy this file into the site. Its calls and a way to build
   a website on it are in the README at https://github.com/plue-gmbh/skemik-php
   SPDX-License-Identifier: 0BSD */
final class Skemik
{
  private array $shown = [];

  private array $versioned = [];

  private array $worded = [];

  public function __construct(
    private string $feed,
    private string $key = '',
    private string $cache = __DIR__ . '/cache',
    private string $media = __DIR__ . '/media',
    private array $kinds = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
      'image/avif' => 'avif', 'image/gif' => 'gif', 'image/svg+xml' => 'svg', 'image/vnd.microsoft.icon' => 'ico',
      'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/mpeg' => 'mpeg', 'video/ogg' => 'ogv',
      'audio/mpeg' => 'mp3', 'audio/ogg' => 'ogg', 'audio/mp4' => 'm4a', 'audio/wav' => 'wav',
      'font/woff2' => 'woff2', 'font/woff' => 'woff', 'font/ttf' => 'ttf', 'font/otf' => 'otf',
      'application/pdf' => 'pdf', 'application/zip' => 'zip']
  ) {
    is_dir($cache) || mkdir($cache);
    is_dir($media) || mkdir($media);
    register_shutdown_function(fn () => $this->copies());
  }

  public function read(string $address): ?array
  {
    return $this->reads([$address])[$address];
  }

  public function reads(array $addresses): array
  {
    $headers = ['Accept: application/ld+json', ...($this->key === '' ? [] : ['Authorization: Bearer ' . $this->key])];
    $files = [];
    foreach ($addresses as $address) {
      $files[$address] = $this->cache . '/' . md5($this->key . $this->whole($address));
    }
    $multi = curl_multi_init();
    curl_multi_setopt($multi, CURLMOPT_MAX_TOTAL_CONNECTIONS, 4);
    curl_multi_setopt($multi, CURLMOPT_PIPELINING, CURLPIPE_NOTHING);
    $fetching = [];
    foreach ($files as $address => $file) {
      $stale = !is_file($file . '.etag') || filemtime($file . '.etag') <= $this->changed();
      $missed = is_file($file) && filesize($file) === 0 && filemtime($file) <= time() - 600;
      if (!is_file($file) || ($stale || $missed) && filemtime($file) <= time() - 60) {
        if (is_file($file)) {
          touch($file);
        }
        $fetching[$file] = $this->fetching($this->whole($address), $file, $headers);
        curl_multi_add_handle($multi, $fetching[$file]);
      }
    }
    do {
      curl_multi_exec($multi, $running);
      if ($running) {
        curl_multi_select($multi);
      }
    } while ($running);
    while ($done = curl_multi_info_read($multi)) {
      $curl = $done['handle'];
      $this->kept(array_search($curl, $fetching, true), $done['result'] === CURLE_OK
        ? curl_getinfo($curl, CURLINFO_RESPONSE_CODE) : 0, curl_multi_getcontent($curl));
    }
    return array_map(fn (string $file): ?array
      => is_file($file) ? json_decode((string) file_get_contents($file), true) : null, $files);
  }

  public function every(string $address): array
  {
    $page = fn (int $number): string
      => $address . (str_contains($address, '?') ? '&' : '?') . 'per_page=200&page=' . $number;
    $first = $this->read($page(1));
    $last = (int) ceil(($first['numberOfItems'] ?? 0) / 200);
    $found = $this->members($first);
    foreach ($this->reads($last > 1 ? array_map($page, range(2, $last)) : []) as $next) {
      $found = [...$found, ...$this->members($next)];
    }
    return $found;
  }

  public function post(string $address, array $document): array
  {
    return $this->write('POST', $address, $document, []);
  }

  public function patch(string $address, array $fields): array
  {
    $this->read($address);
    $tag = $this->cache . '/' . md5($this->key . $this->whole($address)) . '.etag';
    return $this->write('PATCH', $address, $fields, is_file($tag) ? ['If-Match: ' . file_get_contents($tag)] : []);
  }

  public function members(?array $list): array
  {
    return array_map(fn (array $member): array => $this->target($member['item'] ?? $member, 'item'),
      $list['itemListElement'] ?? []);
  }

  public function target(array $value, string $property): array
  {
    if (!in_array($value['@type'] ?? '', ['Role', 'ProgramMembership'], true)) {
      return $value;
    }
    $key = isset($value[$property]) ? $property : 'program';
    return array_merge($this->target($value[$key], 'program'), array_diff_key($value, ['@type' => 1, $key => 1]));
  }

  public function words(string $type): array
  {
    if (isset($this->worded[$type])) {
      return $this->worded[$type];
    }
    $words = [];
    foreach (['', '/profile'] as $address) {
      foreach ($this->read($address)['dataset'] ?? [] as $collection) {
        $about = $collection['about'] ?? '';
        if ((is_array($about) ? $about['@id'] : $about) === 'https://schema.org/' . $type) {
          $words += array_column($collection['variableMeasured'], 'name', 'propertyID');
        }
      }
    }
    return $this->worded[$type] = $words;
  }

  public function word(string $type, string $field): string
  {
    return $this->words($type)[$field] ?? '';
  }

  public function file(array $media): string
  {
    $address = $media['contentUrl'] ?? '';
    $kind = $media['encodingFormat'] ?? null;
    if (!$this->ours($address) || $kind !== null && !isset($this->kinds[$kind])) {
      return $address;
    }
    $copy = $this->copy($address);
    if ($copy === null) {
      $this->shown[] = $address;
      return $address;
    }
    if (filemtime($copy) < time() - 86400) {
      touch($copy);
    }
    return '/' . basename($this->media) . '/' . basename($copy);
  }

  public function versions(array $record): array
  {
    $others = array_column([...$record['translationOfWork'] ?? [], ...$record['workTranslation'] ?? []], '@id');
    $read = $this->versioned[$record['@id']] ??= array_values(array_filter($this->reads($others)));
    return array_column([$record, ...$read], null, 'inLanguage');
  }

  public function site(array $site, array $page): array
  {
    return $page['isPartOf'] ?? $this->versions($site)[$page['inLanguage'] ?? ''] ?? $site;
  }

  public function address(array $site, array $page, array $places = []): string
  {
    $place = $places[basename(dirname($page['@id']))] ?? null;
    if ($place === null) {
      return $page['url'] ?? rtrim($this->site($site, $page)['url'], '/') . '/' . basename($page['@id']);
    }
    $home = $this->versions($site)[$page['inLanguage'] ?? ''] ?? $site;
    return rtrim($home['url'], '/') . '/' . (is_string($place) ? $place : $place($page)) . basename($page['@id']);
  }

  public function head(array $site, array $page, array $places = []): string
  {
    $address = $this->address($site, $page, $places);
    $title = $address === $site['url'] ? $page['name'] : $page['name'] . ' – ' . $site['name'];
    $lines = ['<title>' . htmlspecialchars($title) . '</title>',
      '<meta name="description" content="'
        . htmlspecialchars($page['description'] ?? $site['description'] ?? '') . '">',
      '<link rel="canonical" href="' . htmlspecialchars($address) . '">'];
    $languages = $this->languages($site, $page, $places);
    $english = preg_grep('/^en/', array_keys($languages));
    $tagged = $languages + ($english === [] ? [] : ['x-default' => $languages[reset($english)]]);
    foreach (count($languages) > 1 ? $tagged : [] as $tag => $version) {
      $lines[] = '<link rel="alternate" hreflang="' . htmlspecialchars($tag) . '" href="'
        . htmlspecialchars($version) . '">';
    }
    return implode("\n", $lines);
  }

  public function languages(array $site, array $page, array $places = []): array
  {
    $sites = $this->versions($site);
    $placed = isset($places[basename(dirname($page['@id']))]);
    $versions = isset($page['inLanguage']) ? $this->versions($page)
      : array_fill_keys($placed ? array_keys($sites) : [$site['inLanguage'] ?? ''], $page);
    $standing = array_filter($versions, fn (array $version, $tag): bool => isset($sites[$tag])
      || isset($version['isPartOf']) || $version['@id'] === $page['@id'], ARRAY_FILTER_USE_BOTH);
    $to = [];
    foreach ($standing as $tag => $version) {
      $to[$tag] = $this->address($sites[$tag] ?? $site, $version, $places);
    }
    return $to;
  }

  public function at(array $site, string $address, array $places = []): ?array
  {
    $address = rtrim((string) strtok($address, '?#'), '/');
    $sites = array_filter($this->versions($site), fn (array $version): bool
      => str_starts_with($address . '/', rtrim($version['url'], '/') . '/'));
    usort($sites, fn (array $one, array $other): int => strlen($other['url']) <=> strlen($one['url']));
    $rest = ltrim(substr($address, strlen(rtrim($sites[0]['url'] ?? '', '/'))), '/');
    $under = str_contains($rest, '/') ? dirname($rest) . '/' : '';
    $held = array_keys(array_filter(['web-pages' => ''] + $places, fn ($place): bool
      => !is_string($place) || $place === $under));
    $route = dirname($sites[0]['@id'] ?? '', 2) . '/';
    $asked = $rest === '' ? [$route . 'web-pages?url=' . rawurlencode($sites[0]['url'] ?? '')]
      : array_map(fn (string $collection): string => $route . $collection . '/' . basename($rest), $held);
    $named = $rest === '' || preg_match('/^[a-z0-9-]+$/', basename($rest)) === 1;
    foreach ($sites === [] || !$named ? [] : $this->reads($asked) as $read) {
      foreach (isset($read['itemListElement']) ? $this->members($read) : array_filter([$read]) as $record) {
        if (rtrim($this->address($sites[0], $record, $places), '/') === $address) {
          return $record;
        }
      }
    }
    return null;
  }

  public function sitemap(array $site, array $places = []): string
  {
    $addresses = [];
    foreach ($this->versions($site) as $version) {
      $pages = array_filter($this->every(dirname($version['@id'], 2) . '/web-pages'), fn (array $page): bool
        => $this->site($version, $page)['@id'] === $version['@id']
          && ($page['url'] ?? $version['url']) === $version['url']);
      $about = array_column(array_column($pages, 'mainEntity'), '@id');
      foreach ($pages as $page) {
        $addresses[] = $this->address($version, $page);
      }
      foreach (array_keys($places) as $collection) {
        foreach ($this->every(dirname($version['@id'], 2) . '/' . $collection) as $record) {
          $addresses[] = in_array($record['@id'], $about, true) ? '' : $this->address($version, $record, $places);
        }
      }
    }
    $listed = array_map(fn (string $address): string => '<url><loc>' . htmlspecialchars($address, ENT_XML1)
      . '</loc></url>', array_unique(array_filter($addresses)));
    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
      . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n" . implode("\n", $listed) . "\n</urlset>\n";
  }

  public function named(string $address): string
  {
    return implode('/', array_slice(explode('/', $address), -2));
  }

  public function staff(array $organization): array
  {
    $people = $this->read('/people?worksFor=' . $this->named($organization['@id']));
    $staff = [];
    foreach ($this->members($people) as $person) {
      foreach ($person['worksFor'] ?? [] as $edge) {
        $to = $this->target($edge, 'worksFor');
        if (isset($to['roleName']) && $to['@id'] === $organization['@id']) {
          $staff[$to['roleName']][] = $person;
        }
      }
    }
    return $staff;
  }

  public function local(array $document, array $site, array $places = []): array
  {
    $pattern = '#<a href="(' . preg_quote($this->root(), '#') . '/[^"]+)">(.*?)</a>#s';
    $found = [];
    array_walk_recursive($document, function ($value) use ($pattern, &$found): void {
      preg_match_all($pattern, is_string($value) ? $value : '', $links);
      $found = [...$found, ...$links[1]];
    });
    $to = $this->destinations(array_unique($found), $site, $places);
    array_walk_recursive($document, function (&$value) use ($pattern, $to): void {
      $value = !is_string($value) ? $value : preg_replace_callback($pattern, fn (array $link): string
        => $to[$link[1]] === null ? $link[2] : '<a href="' . htmlspecialchars($to[$link[1]]) . '">' . $link[2] . '</a>',
        $value);
    });
    return $document;
  }

  public function destinations(array $addresses, array $site, array $places = []): array
  {
    $asked = [];
    foreach ($addresses as $address) {
      $asked[$address] = str_contains($address, '/web-pages/') ? $address
        : '/web-pages?mainEntity=' . $this->named($address);
    }
    $answers = $this->reads($asked);
    $records = $this->reads(array_values(array_filter($addresses, fn (string $address): bool
      => ($places[basename(dirname($address))] ?? '') instanceof Closure)));
    $named = fn (array $page): bool => ($page['isPartOf']['@id'] ?? null) === $site['@id'];
    $here = fn (array $page): bool => $this->site($site, $page)['@id'] === $site['@id'];
    $to = [];
    foreach ($asked as $address => $asking) {
      $about = $this->members($answers[$asking]);
      $page = $asking === $address ? $answers[$asking]
        : [...array_filter($about, $named), ...array_filter($about, $here)][0] ?? null;
      $record = $records[$address] ?? (is_string($places[basename(dirname($address))] ?? null)
        ? ['@id' => $address] : null);
      $own = $record === null ? null : $this->address($site, $record, $places);
      $to[$address] = match (true) {
        $page === null && $own === null => null,
        $page === null => parse_url($own, PHP_URL_PATH) ?: '/',
        $here($page) => parse_url($this->address($site, $page), PHP_URL_PATH) ?: '/',
        default => $this->address($site, $page),
      };
    }
    return $to;
  }

  private function ours(string $address): bool
  {
    return preg_match('#^' . preg_quote(dirname($this->root()), '#') . '/media/[0-9a-f-]{36}$#', $address) === 1;
  }

  private function copy(string $address): ?string
  {
    foreach ($this->kinds as $kind) {
      if (is_file($this->media . '/' . basename($address) . '.' . $kind)) {
        return $this->media . '/' . basename($address) . '.' . $kind;
      }
    }
    return null;
  }

  private function copies(): void
  {
    function_exists('fastcgi_finish_request') && fastcgi_finish_request();
    $multi = curl_multi_init();
    curl_multi_setopt($multi, CURLMOPT_MAX_TOTAL_CONNECTIONS, 4);
    $fetching = [];
    foreach (array_unique($this->shown) as $address) {
      $lock = $this->media . '/' . basename($address) . '.lock';
      $left = is_file($this->media . '/' . basename($address) . '.none');
      if ($left || is_file($lock) && filemtime($lock) > time() - 900) {
        continue;
      }
      touch($lock);
      $fetching[$address] = curl_init($address);
      curl_setopt_array($fetching[$address], [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_MAXFILESIZE => 20000000, CURLOPT_USERAGENT => $_SERVER['HTTP_HOST'] ?? 'php',
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => fn ($curl, int $whole, int $read): int => (int) ($read > 20000000)]);
      curl_multi_add_handle($multi, $fetching[$address]);
    }
    do {
      curl_multi_exec($multi, $running);
      if ($running) {
        curl_multi_select($multi);
      }
    } while ($running);
    while ($done = curl_multi_info_read($multi)) {
      $this->copied(array_search($done['handle'], $fetching, true), $done['handle'], $done['result']);
    }
    $this->swept();
  }

  private function swept(): void
  {
    $swept = $this->media . '/.swept';
    if (is_file($swept) && filemtime($swept) > time() - 86400) {
      return;
    }
    touch($swept);
    foreach ([...glob($this->media . '/*') ?: [], ...glob($this->cache . '/*') ?: []] as $file) {
      $ours = preg_match('/^([0-9a-f-]{36}\.|[0-9a-f]{32}(\.|$))/', basename($file)) === 1;
      $kept = dirname($file) === $this->cache && filesize($file) === 0 ? 600 : 2592000;
      if ($ours && filemtime($file) < time() - $kept) {
        unlink($file);
      }
    }
  }

  private function copied(string $address, CurlHandle $curl, int $result): void
  {
    $base = $this->media . '/' . basename($address);
    $kind = $this->kinds[trim(explode(';', (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE))[0])] ?? null;
    $whole = $result === CURLE_OK && curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === 200;
    if ($whole && $kind !== null) {
      file_put_contents($base . '.part.' . getmypid(), curl_multi_getcontent($curl));
      rename($base . '.part.' . getmypid(), $base . '.' . $kind);
      unlink($base . '.lock');
    }
    if ($whole && $kind === null || in_array($result, [CURLE_ABORTED_BY_CALLBACK, CURLE_FILESIZE_EXCEEDED], true)) {
      touch($base . '.none');
    }
  }

  private function write(string $method, string $address, array $document, array $headers): array
  {
    $tag = '';
    $curl = curl_init($this->whole($address));
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 10,
      CURLOPT_POSTFIELDS => json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      CURLOPT_HTTPHEADER => ['Content-Type: application/ld+json', 'Accept: application/ld+json',
        'Authorization: Bearer ' . $this->key, ...$headers],
      CURLOPT_USERAGENT => $_SERVER['HTTP_HOST'] ?? 'php',
      CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$tag): int {
        $tag = stripos($line, 'ETag:') === 0 ? trim(substr($line, 5)) : $tag;
        return strlen($line);
      }]);
    $answer = json_decode((string) curl_exec($curl), true);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $file = $this->cache . '/' . md5($this->key . ($answer['@id'] ?? $this->whole($address)));
    if ($method === 'PATCH' && $status === 200) {
      file_put_contents($file, json_encode($answer));
      file_put_contents($file . '.etag', $tag);
    }
    if ($status === 412) {
      array_map('unlink', glob($file . '*') ?: []);
    }
    return ['status' => $status, 'document' => $answer];
  }

  private function whole(string $address): string
  {
    return str_contains($address, '://') ? $address : $this->feed . $address;
  }

  private function root(): string
  {
    return preg_replace('#(?<!/)/feed\b.*#', '/feed', $this->feed);
  }

  private function changed(): int
  {
    $file = $this->cache . '/' . md5($this->root()) . '.date';
    [$date, $since] = is_file($file) ? explode("\n", (string) file_get_contents($file)) + ['', '0'] : ['', '0'];
    if (!is_file($file) || filemtime($file) <= time() - 60) {
      file_put_contents($file, $date . "\n" . $since);
      $curl = $this->fetching($this->root(), $file, ['Accept: application/ld+json']);
      $seen = json_decode((string) curl_exec($curl), true)['dateModified'] ?? $date;
      if (curl_errno($curl) !== 0 && is_file($file . '.etag')) {
        unlink($file . '.etag');
      }
      if ($seen !== $date) {
        $date = $seen;
        $since = time() + 60;
        file_put_contents($file, $date . "\n" . $since);
      }
    }
    return (int) $since;
  }

  private function fetching(string $address, string $file, array $headers): CurlHandle
  {
    if (is_file($file . '.etag') && filesize($file . '.etag') > 0) {
      $headers[] = 'If-None-Match: ' . file_get_contents($file . '.etag');
    }
    $curl = curl_init($address);
    $etag = function ($curl, string $line) use ($file): int {
      if (stripos($line, 'ETag:') === 0) {
        file_put_contents($file . '.etag', trim(substr($line, 5)));
      }
      return strlen($line);
    };
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
      CURLOPT_TIMEOUT => 5, CURLOPT_HEADERFUNCTION => $etag,
      CURLOPT_USERAGENT => $_SERVER['HTTP_HOST'] ?? 'php']);
    return $curl;
  }

  private function kept(string $file, int $status, ?string $body): void
  {
    if ($status === 200 && $body !== null) {
      file_put_contents($file . '.part', $body);
      rename($file . '.part', $file);
      return;
    }
    if ($status === 404 && is_array(json_decode((string) $body, true))) {
      file_put_contents($file, '');
      file_put_contents($file . '.etag', '');
      return;
    }
    if ($status === 0 && is_file($file . '.etag')) {
      unlink($file . '.etag');
    }
    if (is_file($file)) {
      touch($file);
    }
  }
}
