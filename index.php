<?php

// НЕОБХОДИМО чтобы было подключено расширение 'dom' (используются классы DOMDocument, DOMNode и т.п.)

/*
Зарегистрируйтесь на Яндексе.

После чего перейдите по адресу http://xml.yandex.ru/
Щелкните по ссылке "Введите свой IP адрес" и вбейте IP адрес сервера.
Усё, теперь яндекс будет отвечать на наши запросы.

*/


// дополнить запрос текущими параметрами (здесь поиск будет производиться по страницам  http://kolesa.kz/content/*)
define("SEARCH_ADT_QUERY","<<  url=\"kolesa.kz/*\"");

// на страницу будет выведено 10 результатов
define("SEARCH_RESULT_PER_PAGE",10);

// эти дефайны можно не изменять
define("SEARCH_SHELL_HOST","xmlsearch.yandex.ru");
define("SEARCH_SHELL_PORT","80");
define("SEARCH_SHELL_HOST_IN_POST","xmlsearch.yandex.ru");
define("SEARCH_SHELL_SCRIPT","xmlsearch");


// Вызывается все это следующим образом:
$ya = new YandexSearch();
if(strlen($stext)>0) {
    if ($ya->do_search($stext, $spage)) {
        echo iconv('utf-8','windows-1251',$ya->get());
    } else {
        echo "Извините поиск временно не работает.";
    }
} else {
    echo $ya->get_search_form();
}



class YandexSearch {
    private $headers=array(); // заголовки ответа от сервера яндекса
    private $answer; // ответ от сервера яндекса
    private $page; // номер страницы
    private $text; // текст поиска
    private $tags; // слова запроса заенкоденные

    /** Здесь формируется запрос на сервер, отправляется и получает XML ответ от сервера в UTF8
     *
     * @return string $body;
     */
    private function get_xml_search() {
        $data="text=".urlencode(""
                ."<?xml version=\"1.0\" encoding=\"windows-1251\"?>"
                ."<request>"
                ."<query>".htmlspecialchars($this->text)." ".SEARCH_ADT_QUERY."</query>"
                ."<maxpassages>2</maxpassages>"
                ."<groupings>"
                ."<groupby attr=\"\" mode=\"flat\" groups-on-page=\"".SEARCH_RESULT_PER_PAGE."\"  docs-in-group=\"1\" />"
                ."</groupings>"
                ."<page>".($this->page-1)."</page>"
                ."<max-headline-length>200</max-headline-length>"
                ."<max-passage-length>150</max-passage-length>"
                ."<max-title-length>150</max-title-length>"
                ."<max-text-length>200</max-text-length>"
                ."</request>");

        $rn="\r\n";
        $zapros=
            "POST /".SEARCH_SHELL_SCRIPT." HTTP/1.1".$rn.
            "Referer: http://kolesa.kz".$rn.
            "Content-Type: application/xml;".$rn. //charset=utf-8
            "Content-Length: ".strlen($data).$rn.
            "Host: ".SEARCH_SHELL_HOST_IN_POST."".$rn.
            "Accept: */*".$rn.
            "User-Agent: Mozilla/4.0 (compatible; MSIE 5.01; Windows NT)".$rn.
            "".$rn.$data;

        $fp = @fsockopen(SEARCH_SHELL_HOST, SEARCH_SHELL_PORT, $errno, $errstr, 30);

        $body = '';
        if($fp) {
            @fputs($fp,$zapros);
            $this->headers['answer']=array();
            $body='';
            $this->decode_sock($fp, $this->headers['answer'], $body);
            @fclose($fp);
        }
        echo $body;
        return $body;
    }


    /** Получаем заголовки ответа
     *
     * @return array;
     */
    private function decode_sock_header($str)
    {
        $part=preg_split("/\r?\n/", $str, -1, PREG_SPLIT_NO_EMPTY );
        $out=array ();

        for($h=0;$h<sizeof($part);$h++) {
            if($h!=0) {
                $pos=strpos($part[$h],':');
                $k=strtolower(str_replace(' ','',substr($part[$h],0,$pos)));
                $v=trim(substr($part[$h],($pos+1)));
            } else {
                $k='status';
                $v=explode(' ',$part[$h]);
                $v=$v[1];
            }

            if($k=='set-cookie') {
                $out['cookies'][]=$v;
            } elseif ($k=='content-type') {
                if(($cs=strpos($v,';'))!==false) {
                    $out[$k]=substr($v, 0, $cs);
                } else {
                    $out[$k]=$v;
                }
            } else {
                $out[$k]=$v;
            }
        }
        return $out;
    }


    /**
     * Получаем тело ответа, избавляемся от "чанков"
     */
    private function decode_sock_body(&$headers, &$body, $eol="\r\n")
    {
        $tmp=$body;
        $add=strlen($eol);
        $body='';
        if($headers['transfer-encoding']=='chunked') {
            do {
                $tmp=ltrim($tmp);
                $pos=strpos($tmp, $eol);
                $len=hexdec(substr($tmp,0,$pos));

                if(isset($headers['content-encoding'])) {
                    $body.=gzinflate(substr($tmp, ($pos+$add+10), $len));
                } else {
                    $body.=substr($tmp, ($pos+$add), $len);
                }

                $tmp=substr($tmp, ($len+$pos+$add));
                $check=trim($tmp);
            } while(!empty($check));
        } elseif(isset($headers['content-encoding'])) {
            $body=gzinflate(substr($tmp,10));
        }
    }


    /**
     * Обрабатываем ответ от сервера
     * @return array;
     */
    private function decode_sock($io, &$headers, &$body, $eol="\r\n") {
        $send = '';
        do {
            $send.=fgets($io, 4096);
        } while(strpos($send, $eol.$eol)===false);
        $headers=$this->decode_sock_header($send);

        while(!feof($io)) {
            $body.=fread($io, 8192);
        }

        // передаем ссылку на $body, для перекодировки
        $this->decode_sock_body($headers, $body, $eol);
    }


    /** Конвертируем XML ответ от сервера в Ассоциативный массив
     *
     * @return array;
     */
    private function xml2array($xml) {
        if($xml) {
            $this->doc_obj=new DOMDocument('1.0');
            $this->doc_obj->xmlStandalone=true;
            $rez=$this->doc_obj->loadXML($xml);
        }

        if($rez) {
            return $this->xml2array_level($this->doc_obj);
        } else {
            return Array();
        }
    }


    /** Конвертируем XML ответ от сервера в Ассоциативный массив (рекурсивная функция)
     *
     * @return array;
     */
    private function xml2array_level($node) {
        $res = array();
        if($node->nodeType == XML_TEXT_NODE){
            $res = htmlspecialchars_decode($node->nodeValue);
        } else {
            if($node->hasAttributes()){
                $attributes = $node->attributes;
                if(!is_null($attributes)){
                    $res['#attr'] = array();
                    foreach ($attributes as $index=>$attr) {
                        $res['#attr'][$attr->name]=$attr->value;
                    }
                }
            }

            if($node->hasChildNodes()){
                $children = $node->childNodes;

                $occurency=array();
                $k=0;
                for($i=0;$i<$children->length;$i++){
                    $child = $children->item($i);
                    $occurency[$child->nodeName]++;
                    $k++;
                }

                for($i=0;$i<$children->length;$i++){
                    $child = $children->item($i);

                    if($child->nodeName!="#text"){
                        if($occurency[$child->nodeName]>1) {
                            $res[$child->nodeName]['#isset']=1;
                            $res[$child->nodeName][]=$this->xml2array_level($child);
                        } else {
                            $res[$child->nodeName]=$this->xml2array_level($child);
                        }
                    } elseif($k==1) {
                        if(count($res)==0) {
                            $res=$node->nodeValue;
                        } else {
                            $res=$res+Array('#text'=>$node->nodeValue);
                        }
                    }
                }
            } elseif (!count($res)) {
                $res="";
            }
        }

        return $res;
    }


    /**
     * Получаем текст одного результата поиска
     *
     * @return string;
     */
    private function get_result_text(&$val) {
        $cout = '';

        if(is_array($val['doc']['passages']['passage'])) {
            foreach($val['doc']['passages']['passage'] as &$value) {
                if($value!=1) {
                    $cout.=$value."<br>";
                }
            }
            $cout=substr($cout,0,-4);
        } elseif($val['doc']['passages']['passage']!="") {
            $cout.=$val['doc']['passages']['passage'];
        }

        return $cout;
    }


    /**
     * Подготавливаем теги, из слов поискового запроса
     *
     * @return string;
     */
    private function prepare_tags() {
        if ($this->tags) {
            return $this->tags;
        }

        $text = '';
        $tok=strtok(preg_replace('/[^-0-9A-zА-яЁё\-\s.]/i', " ", $this->text), " ");
        while ($tok !== false) {
            $text .= urlencode($tok).",";
            $tok=strtok(" ");
        }
        $this->tags = substr($text, 0, -1);

        return $this->tags;
    }


    /** Подготавливаем ссылки, добавляем теги (для подсветки найденных слов на страницах)
     *
     * @return string;
     */
    private function prepare_url($url) {
        $anchor_pos=strpos($url,"#");

        if($anchor_pos>0) {
            $url=substr($url,0,$anchor_pos-1);
        }

        if(strpos($url,"?")>0) {
            $url.="&tags=".$this->prepare_tags();
        } else {
            $url.="?tags=".$this->prepare_tags();
        }

        return $url;
    }



    /**
     * Производим поиск
     *
     * @return boolean;
     */
    public function do_search($text,$spage) {
        $this->text=$text;
        $this->tags="";

        $spage*=1;
        if($spage<1) $spage=1;
        $this->page=$spage;

        $rez=$this->get_xml_search();
        // меняем xml-тег "<hlword>" на html-тег "<b>"
        $rez=preg_replace(array("/<hlword [^>]*>/i","/<\\/hlword>/i"), array("<b>", "</b>"), $rez);
        $this->answer=$this->xml2array($rez);

        if (!is_array($this->answer['yandexsearch']['response']['results'])&&!isset($this->answer['yandexsearch'])) {
            return false;
        }

        return true;
    }



    /**
     * Получаем кол-во найденных результатов
     *
     * @return integer;
     */
    public function get_found_num_result() {
        return $this->answer['yandexsearch']['response']['results']['grouping']['found']['2']['#text']*1;
    }


    ////////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////////
    ////////////////////////////////////////////////////////////////////////////

    // Псевдо-Шаблон поисковой формы (!результат должен быть в Windows-1251!)

    public function get_search_form() {
        return "
             <p style=\"line-height:2;\">
            <nobr><form method=\"get\" action=\"?page=1\"><input name=\"stext\" type=\"text\" style=\"width:80%; font-size:1.4em;\" value=\"".$this->text."\" />  <input type=\"submit\" value=\"Найти\" /></form>
            </nobr></p>
        ";
    }



    // Псевдо-Шаблон заголовка (!результат должен быть в UTF8!)
    private function get_header_template() {
        $cout = $this->get_search_form();
        $cout .= "
            <style>
            #search-results { margin: 0; padding: 0; }
            #search-results LI { padding-left: 0; margin-left: 0; list-style-type:none;  }
            #search-results A { font-size: 1.1em;}
            #search-results A.link { color: #999; font-size: .8em; text-decoration: none; font-family: 'Verdana', Arial, sans-serif; margin-left:0 !important; }
            *html #search-results A { margin-left: -1.15em;}
            </style>";

        $cout.="<p align=\"right\">Поиск реализован на основе <a href=\"http://xml.yandex.ru/\" target=\"_blank\">Яндекс.XML</a></p>";
        $cout.="<h2>Всего найдено страниц: ".$this->get_found_num_result()."</h2>";

        return iconv('windows-1251','utf-8',$cout);
    }



    // Псевдо-Шаблон футера (!результат должен быть в UTF8!)
    private function get_footer_template() {
        return iconv('windows-1251','utf-8', $cout);
    }

    // Псевдо-Шаблон ОДНОЙ записи результата (!результат должен быть в UTF8!)
    private function get_row_template(&$val,$result_num) {
        if(strlen($val['doc']['title'])<2) {
            return "";
        }

        $cout = "<li><p>";
        $cout .= "<strong>".$result_num.".       "."<a href=\"".$this->prepare_url($val['doc']['url'])."\" target=\"_blank\">".$val['doc']['title']."</a></strong>";
        $cout .= "<br />";
        $cout .= $this->get_result_text($val);
        $cout .= "<br />";
        $cout .= "<a href=\"".$this->prepare_url($val['doc']['url'])."\" class=\"link\" target=\"_blank\">".$val['doc']['url']."</a>";
        $cout .= "</p></li>";

        return $cout;
    }


    // Проход по найденным записям результатов (!результат должен быть в UTF8!)
    private function get_all_rows_template() {
        $cout = "<ol id=\"search-results\">";
        if(is_array($this->answer['yandexsearch']['response']['results']['grouping']['group'])) {
            $k=$this->answer['yandexsearch']['response']['results']['grouping']['page']['#attr']['first']*1;
            foreach($this->answer['yandexsearch']['response']['results']['grouping']['group'] as &$val) {
                if(is_array($val)) {
                    $cout.=$this->get_row_template($val,$k++);
                }
            }
        } else {
            $cout.=iconv('windows-1251','utf-8','Извините, по вашему запросу ничего не найдено.<br><br>');
        }
        $cout.="</ol>";
        return $cout;
    }

    // список страниц
    private function get_page_list() {
        $n=$this->get_found_num_result();
        $page_num=ceil($n/SEARCH_RESULT_PER_PAGE);

        if($page_num<=1) {
            return "";
        }

        $cout = "<br /><br /><div class=\"pages\">";
        $cout .= iconv('windows-1251','utf-8',"<span style=\"font-size:110%\"><b>Страницы:</b></span><br /><br />");

        if($this->page-5>1) {
            $cout.="<div>...</div>";
        }

        for($i=max(1,$this->page-5);$i<=min($this->page+5,$page_num);$i++) {
            $url="?spage=".$i."&stext=".urlencode($this->text)."";

            if($i==$this->page) {
                $cout.="<div class=\"current\">".$i."</div>";
            } else {
                $cout.="<div><a href=\"".$url."\">".$i."</a> </div>";
            }
        }

        if($this->page+5<$page_num) {
            $cout.="<div>...</div>";
        }

        $cout.="</div>";
        return $cout;
    }

    // формирование страницы результатов
    public function get() {
        $cout = $this->get_header_template();
        $cout.=$this->get_all_rows_template();
        $cout.=$this->get_page_list();
        $cout.=$this->get_footer_template();

        return $cout;
    }
}
