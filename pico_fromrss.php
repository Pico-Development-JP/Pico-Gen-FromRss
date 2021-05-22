<?php
/**
 * Pico FromRSS
 * RSSを読み込み、
 *
 * @author TakamiChie
 * @link http://onpu-tamago.net/
 * @license http://opensource.org/licenses/MIT
 * @version 1.0
 */
class Pico_FromRSS {
  
  private $settings;

  public function run($settings) {
    if(empty($settings["fromrss"]) ||
      empty($settings["fromrss"]["entries"])) {
      return;
    }
    $this->settings = $settings;

    $entries = $settings["fromrss"]["entries"];

    foreach($entries as $entry){
      $this->loadentry($entry);
    }
	}
  
  private function loadentry($entry) {
    if(empty($entry) ||
      empty($entry["rss"]) ||
      empty($entry["directory"])){
      return;
    }
    $entrydata = array('count' => 5) + $entry;
    
    $rssmd5 = md5($entrydata["rss"]);
    $cdir = $this->settings["content_dir"] . $entrydata["directory"];
    $cachedir = LOG_DIR . "fromrss/";
    $cachefile = $cachedir . $rssmd5 . ".xml";
    echo sprintf("%s(%s)\n", $entrydata['rss'], $rssmd5);
    if(!file_exists($cdir)){
      mkdir($cdir, "0500", true);
    }
    if(!file_exists($cachedir)){
      mkdir($cachedir, "0500", true);
    }

    /* テキストファイル作成処理 */
    try{
      $responce;
      // まずは読み込み
      $content = $this->curl_getcontents($entrydata['rss'], $responce);
      file_put_contents($cachefile, $content);
      $xml = new DOMDocument();
      $xml->preserveWhiteSpace = false;
      $xml->loadXML($content);
      $xpath = new DOMXPath($xml);
      switch ($xml->childNodes[0]->nodeName) {
        case 'rss':
          # RSS
          $rootnode = "/rss/channel/item";
          $titlenode = "title";
          $authornode = "/rss/channel/title";
          $pubdatenode = "pubDate";
          $bodynode = "description";
          $linknode = "link";
          $idnode = "guid";
          break;
        case 'feed':
          # Atom
          $rootnode = "//*[local-name()='entry']";
          $titlenode = "*[local-name()='title']";
          $authornode = "//*[local-name()='title']";
          $pubdatenode = "*[local-name()='published']";
          $bodynode = "*[local-name()='summary' or local-name()='content']";
          $linknode = "*[local-name()='link']/@href";
          $idnode = "*[local-name()='id']";
          break;
        default:
          throw new Exception("Unknown XML File " . $entry["rss"]);
          break;
      }
      if($responce['http_code'] >= 300){
        throw new Exception("HTTP Error: " . $responce['http_code']);
      }else{
        echo "Load success\n";
      }
      $this->removeBeforeScanned($cdir);
      $i = 0;
      $authorname = $xpath->query($authornode)[0]->textContent;
      foreach($xpath->query($rootnode) as $j){
        if(array_key_exists("query", $entry)){
          // 記事は条件に合致する？
          switch (gettype($entry['query'])) {
            case 'string':
              if(count($xpath->query($entry['query'], $j)) == 0) continue 2;
              break;
            case 'array':
              foreach ($entry['query'] as $q) {
                if(count($xpath->query($q, $j)) == 0) continue 3;
              }
              break;
            default:
              throw new Exception("Unknown query type!", 1);
          }
        }
        if($i++ >= $entry['count']) break;
        // mdファイル作成
        $page = "---\n";
        $page .= sprintf("Title: %s\n", $xpath->query($titlenode, $j)[0]->textContent);
        $page .= sprintf("Author: %s\n", $authorname);
        $page .= sprintf("Date: %s\n", $xpath->query($pubdatenode, $j)[0]->textContent);
        $page .= sprintf("URL: %s\n", $xpath->query($linknode, $j)[0]->textContent);
        $page .= "---\n";
        $page .= $xpath->query($bodynode, $j)[0]->textContent;

        $fn = md5($xpath->query($idnode, $j)[0]->textContent) . ".md";
        echo $fn . " Save Success\n";
        file_put_contents($cdir . $fn, $page);
      }
      exit();
    }catch(Exception $e){
      echo $e->getMessage();
    }
	}

  /**
   *
   * ファイルをダウンロードする
   *
   * @param string $url URL
   * @param array $responce レスポンスヘッダが格納される配列(参照渡し)。省略可能
   *
   */
  private function curl_getcontents($url, &$responce = array())
  {
    $ch = curl_init();
    curl_setopt_array($ch, array(
      CURLOPT_URL => $url,
      CURLOPT_TIMEOUT => 10,
    	CURLOPT_CUSTOMREQUEST => 'GET',
    	CURLOPT_SSL_VERIFYPEER => FALSE,
    	CURLOPT_RETURNTRANSFER => TRUE,
    	CURLOPT_USERAGENT => "Pico"));

    $content = curl_exec($ch);
    if(!curl_errno($ch)) {
      $responce = curl_getinfo($ch);
    } 
    if(!$content){
      throw new Exception(curl_error($ch));
    }
    curl_close($ch);
    return $content;
  }

  /**
   *
   * 以前自動生成した原稿ファイルを全削除する
   *
   * @param string $cdir 対象のファイルが格納されているディレクトリパス
   *
   */
  private function removeBeforeScanned($cdir){
    if($handle = opendir($cdir)){
      while(false !== ($file = readdir($handle))){
        if(!is_dir($file) && $file != "index.md"){
          unlink($cdir. "/" . $file);
        }
      }
      closedir($handle);
    }
  }
}

?>
