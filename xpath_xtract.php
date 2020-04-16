<?php

$html = new DomDocument();
// This is a .docx converted by Google Docs -> export HTML
$html->loadHTMLFile('./2020_03_30FAQgesamt.docx.html');

$xpath = new DomXPath($html);
// Table of Contents
$tocResults = $xpath->query("//*[starts-with(@href, '#')]");

$questionPattern = "//ol[li[1]/span[1][contains(string(), '?')]]";
$h2Resolver = function(int $index) use ($xpath, $questionPattern) {
    $results = $xpath->query("{$questionPattern}[{$index}]/preceding-sibling::h2[@id][1]");
    return $results[0];
};

$questionResults = $xpath->query($questionPattern);

$headlines = [];

$toc = [];
foreach ($tocResults as $result) {
    $href = $result->attributes->getNamedItem('href')->nodeValue;
    /** @var DOMElement $result */
    $toc[$href] = ($toc[$href] ?? '') . preg_replace(
        [
            '/[ \s]+/', // strip multiple whitespaces
            '/[ \s]+\d+$/', // strip page ref
            '/\xA7/', // &sect; unicode
        ],
        [
            ' ', // strip multiple whitespaces
            '', // strip page ref
            '&sect;', // &sect; unicode
        ],
        $result->nodeValue
    );
}

$currCatLvl1 = null;
foreach ($toc as $href => $headline) {
    if (preg_match('/^\d+\./', $headline)) {
        // cat-lvl1
        $toc[$href] = [
            'href' => $href,
            'headline' => preg_replace('/^\d+\./', '', $headline),
            'cats' => [],
        ];
        $currCatLvl1 = &$toc[$href];
    } else {
        // cat-lvl2
        $currCatLvl1['cats'][$href] = [
            'href' => $href,
            'headline' => $headline,
        ];
        unset($toc[$href]);
    }
}
unset($currCatLvl1);

$questions = [];
$index = 0;
foreach ($questionResults as $result) {
    $noInCat = $result->attributes->getNamedItem('start')->nodeValue;
    $questions[$index] = ['thema' => $noInCat . '. ' . $result->nodeValue];
    $questions[$index]['cat_href'] = '#' . $h2Resolver($index+1)
            ->attributes
            ->getNamedItem('id')
            ->nodeValue;

    $next = $result;
    $content = '';

    while (
        ($next = $next->nextSibling) // not last node
        && !('ol' === $next->tagName && preg_match('/\?$/', $next->nodeValue)) // next question
        && !('h2' === $next->tagName && $next->attributes->getNamedItem('id') && null !== $next->attributes->getNamedItem('id')->nodeValue) // next sub section
        && !('ol' === $next->tagName && 1 === preg_match('/\<h1/', $next->ownerDocument->saveHTML($next))) // next main section
    ) {
        $htmlNode = $next->ownerDocument->saveHTML($next);
        $content .= $htmlNode . PHP_EOL;
    }

    $questions[$index]['content'] = $content;
    $index++;
}
unset($index, $content);

$sql= [];
$sql[] = 'USE phpmyfaq;';
$sql[] = 'BEGIN;';
$sql[] = 'DELETE FROM faq_faqcategories WHERE id >= 1;';
$sql[] = 'DELETE FROM faq_faqcategory_group WHERE category_id >= 1;';
$sql[] = 'DELETE FROM faq_faqcategory_user WHERE category_id >= 1;';
$sql[] = 'DELETE FROM faq_faqdata WHERE id >= 1;';
$sql[] = 'DELETE FROM faq_faqdata_group WHERE record_id >= 1;';
$sql[] = 'DELETE FROM faq_faqdata_user WHERE record_id >= 1;';
$sql[] = 'DELETE FROM faq_faqcategoryrelations WHERE category_id >= 1;';
$sql[] = 'DELETE FROM faq_faqchanges WHERE beitrag >= 1;';
$sql[] = 'SET @main_id = (SELECT COALESCE(MAX(id)+1, 1) FROM faq_faqcategories);';
$mainCatIndex = 1;
foreach ($toc as $href => $mainCat) {
    $sql[] = sprintf(
        'INSERT INTO faq_faqcategories ('
            . 'id, lang, parent_id, name, description, user_id, group_id, active, show_home'
        . ') VALUES (@main_id, "%s", 0, "%d. %s", "%s", 1, 0, 1, 1);',
        'de',
        $mainCatIndex++,
        trim($mainCat["headline"]),
        $href
    );
    $sql[] = "INSERT INTO faq_faqcategory_group (category_id,group_id) VALUES (@main_id,-1);";
    $sql[] = "INSERT INTO faq_faqcategory_user (category_id,user_id) VALUES (@main_id,-1);";

    $subCatIndex = 1;
    foreach ($mainCat['cats'] as $href => $subCat) {
        $sql[] = 'SET @next_id = (SELECT MAX(id)+1 FROM faq_faqcategories);';
        $sql[] = sprintf(
            'INSERT INTO faq_faqcategories ('
                . 'id, lang, parent_id, name, description, user_id, group_id, active, show_home'
            . ') VALUES (@next_id, "%s", @main_id, "%d. %s", "%s", 1, 0, 1, 1);',
            'de',
            $subCatIndex++,
            trim($subCat["headline"]),
            $href
        );
        $sql[] = "INSERT INTO faq_faqcategory_group (category_id,group_id) VALUES (@next_id,-1);";
        $sql[] = "INSERT INTO faq_faqcategory_user (category_id,user_id) VALUES (@next_id,-1);";
    }

    $sql[] = 'SET @main_id = (SELECT MAX(id)+1 FROM faq_faqcategories);';
}

foreach ($questions as $question) {
    $sql[] = 'SET @next_data_id = (SELECT COALESCE(MAX(id)+1, 1) FROM faq_faqdata);';
    $sql[] = sprintf(
        'INSERT INTO faq_faqdata (id, lang, solution_id, revision_id, active, sticky, thema, content, author, email, comment, updated)'
        . ' SELECT @next_data_id, "de", @next_data_id+1000, 0, "yes", 0, "%s", "%s", "%s", "%s", "n", FROM_UNIXTIME(UNIX_TIMESTAMP(), "%s");',
        $question['thema'],
        addslashes($question['content']),
        "ExampleOrg User",
        "foo@bar.tld",
        '%Y%m%d%h%i%s'
    );
    $sql[] = 'INSERT INTO faq_faqdata_group(record_id,group_id) VALUES(@next_data_id,-1);';
    $sql[] = 'INSERT INTO faq_faqdata_user(record_id,user_id) VALUES(@next_data_id,-1);';

    $sql[] = sprintf(
        'INSERT INTO faq_faqcategoryrelations (category_id, category_lang, record_id, record_lang)'
        . ' SELECT (SELECT id FROM faq_faqcategories WHERE description = "%s"), "de", @next_data_id, "de";',
        $question['cat_href']
    );

    $sql[] = 'INSERT INTO faq_faqchanges (id,beitrag,lang,revision_id,usr,datum,what)'
        .' SELECT (SELECT COALESCE(MAX(id)+1,1) FROM faq_faqchanges),@next_data_id,"de",0,2,UNIX_TIMESTAMP(),"";';
}

$sql[] = '';
$sql[] = 'COMMIT;';
$sql[] = '';
$sql[] = '';
$sql[] = '';
$sql[] = '';

echo implode(PHP_EOL, $sql);


