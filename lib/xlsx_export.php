<?php
/**
 * Minimal .xlsx writer: one sheet, optional title block, bold frozen header, numeric money columns.
 * Pure PHP (stored ZIP, no compression) so it needs neither Composer nor the zip extension.
 * Cells are inline strings, never formulas, so spreadsheet formula injection does not apply.
 */
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){http_response_code(404);exit;}

/**
 * $title: optional lines above the table — line 0 is the big bold title, the rest are grey notes
 * (filters, export time…). "{count}" in any line becomes the number of data rows written.
 */
function hxl_send(string $filename,array $header,iterable $rows,array $numericCols=[],array $title=[]): never {
    $xml=fn($v)=>htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u','',(string)$v)??'',ENT_QUOTES|ENT_XML1,'UTF-8');
    $col=function(int $i):string{$s='';for($i++;$i>0;$i=intdiv($i-1,26))$s=chr(65+($i-1)%26).$s;return $s;};
    $text=fn(string $ref,$v,int $style)=>'<c r="'.$ref.'" t="inlineStr"'.($style?' s="'.$style.'"':'').'><is><t xml:space="preserve">'.$xml($v).'</t></is></c>';
    $cells=function(array $values,int $r,bool $head)use($text,$col,$numericCols):string{
        $out='<row r="'.$r.'">';
        foreach(array_values($values) as $i=>$v){
            $ref=$col($i).$r;
            if(!$head&&in_array($i,$numericCols,true)&&is_numeric($v))$out.='<c r="'.$ref.'" s="2"><v>'.(0+$v).'</v></c>';
            else $out.=$text($ref,$v,$head?1:0);
        }
        return $out.'</row>';
    };
    // Title lines, one blank row, then the header row (frozen + auto-filtered) and data.
    $title=array_values(array_filter($title,fn($line)=>(string)$line!==''));
    $headRow=$title?count($title)+2:1;$last=$col(count($header)-1);
    $body=$cells($header,$headRow,true);$r=$headRow;foreach($rows as $row)$body.=$cells($row,++$r,false);
    $top='';$merges='';
    foreach($title as $i=>$line){
        $top.='<row r="'.($i+1).'"'.($i===0?' ht="24" customHeight="1"':'').'>'.$text('A'.($i+1),str_replace('{count}',number_format($r-$headRow),(string)$line),$i===0?3:4).'</row>';
        $merges.='<mergeCell ref="A'.($i+1).':'.$last.($i+1).'"/>';
    }
    $body=$top.$body;
    $widths='';foreach(array_keys($header) as $i)$widths.='<col min="'.($i+1).'" max="'.($i+1).'" width="'.(in_array($i,$numericCols,true)?14:22).'" customWidth="1"/>';
    $files=[
        '[Content_Types].xml'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
        '_rels/.rels'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        // Style 0 = default, 1 = bold header on grey, 2 = number with thousands separator (built-in format 3),
        // 3 = title (bold 16pt), 4 = title note (grey).
        'xl/styles.xml'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="4"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="16"/><name val="Calibri"/></font><font><i/><sz val="10"/><color rgb="FF667085"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF2F4F7"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="5"><xf/><xf fontId="1" fillId="2" applyFont="1" applyFill="1"/><xf numFmtId="3" applyNumberFormat="1"/><xf fontId="2" applyFont="1"/><xf fontId="3" applyFont="1"/></cellXfs></styleSheet>',
        'xl/worksheets/sheet1.xml'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="'.$headRow.'" topLeftCell="A'.($headRow+1).'" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>'.$widths.'</cols><sheetData>'.$body.'</sheetData><autoFilter ref="A'.$headRow.':'.$last.max($headRow,$r).'"/>'.($merges?'<mergeCells count="'.count($title).'">'.$merges.'</mergeCells>':'').'</worksheet>',
    ];
    // Stored ZIP: local headers + data, then the central directory and its end record.
    [$dosTime,$dosDate]=(function(){$t=getdate();return [($t['hours']<<11)|($t['minutes']<<5)|intdiv($t['seconds'],2),(($t['year']-1980)<<9)|($t['mon']<<5)|$t['mday']];})();
    $zip='';$dir='';
    foreach($files as $name=>$data){
        $crc=crc32($data);$len=strlen($data);$meta=pack('vvvvvVVVvv',20,0x0800,0,$dosTime,$dosDate,$crc,$len,$len,strlen($name),0);
        $dir.=pack('Vv',0x02014b50,20).$meta.pack('vvvVV',0,0,0,0,strlen($zip)).$name;
        $zip.=pack('V',0x04034b50).$meta.$name.$data;
    }
    $zip.=$dir.pack('VvvvvVVv',0x06054b50,0,0,count($files),count($files),strlen($dir),strlen($zip),0);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$filename.'"');header('Content-Length: '.strlen($zip));header('X-Content-Type-Options: nosniff');
    echo $zip;exit;
}
