<?php

declare(strict_types=1);

namespace LeKoala\SpreadCompat\Xlsx;

use Exception;
use Generator;
use ZipArchive;
use SimpleXMLElement;
use ZipStream\ZipStream;
use LeKoala\SpreadCompat\SpreadCompat;
use LeKoala\SpreadCompat\Xlsx\XlsxAdapter;

/**
 * This class allows you to read and write xlsx easily if you
 * don't have Simple or OpenSpout installed
 */
class Native extends XlsxAdapter
{
    public function readFile(
        string $filename,
        ...$opts
    ): Generator {
        $this->configure(...$opts);

        $zip = new ZipArchive();
        $zip->open($filename);

        $ssData = null;
        $wsData = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!$name) {
                continue;
            }
            if (str_contains($name, 'xl/sharedStrings.xml')) {
                $ssData = $zip->getFromName($name);
            } elseif (str_contains($name, 'xl/worksheets/sheet1.xml')) {
                $wsData = $zip->getFromName($name);
            }
        }
        $zip->close();

        if (!$wsData) {
            throw new Exception("No data");
        }

        // Process shared strings. Obviously this doesn't work for super large documents
        $ss = [];
        if ($ssData) {
            $i = 0;

            $ssXml = new SimpleXMLElement($ssData);
            foreach ($ssXml->children() as $node) {
                $ss[$i] = (string)$node->t;
                $i++;
            }
        }

        // Process data
        $wsXml = new SimpleXMLElement($wsData);
        $headers = null;
        foreach ($wsXml->sheetData->children() as $row) {
            $r = [];
            foreach ($row->children() as $c) {
                $t = (string)$c->attributes()->t;

                $r[] = match ($t) {
                    's' => $ss[(int)$c->v] ?? '',
                    default => (string)$c->v,
                };
            }
            if (empty($r) || $r[0] === "") {
                continue;
            }
            if ($this->assoc) {
                if ($headers === null) {
                    $headers = $r;
                    continue;
                }
                $r = array_combine($headers, $r);
            }
            yield $r;
        }
    }

    /**
     * @param ZipStream $zip
     * @param iterable $data
     * @return void
     */
    protected function write($zip, iterable $data): void
    {
        $allFiles = [
            '_rels/.rels' => $this->genRels(),
            'docProps/app.xml' => $this->genAppXml(),
            'docProps/core.xml' => $this->genCoreXml(),
            'xl/styles.xml' => $this->genStyles(),
            'xl/workbook.xml' => $this->genWorkbook(),
            // 'xl/worksheets/sheet1.xml' => $this->genWorksheet($data),
            'xl/_rels/workbook.xml.rels' => $this->genWorkbookRels(),
            '[Content_Types].xml' => $this->genContentTypes(),
        ];

        foreach ($allFiles as $path => $xml) {
            $zip->addFile($path, $xml);
        }

        // End up with worksheet
        $stream =  $this->genWorksheet($data);
        rewind($stream);
        $zip->addFileFromStream('xl/worksheets/sheet1.xml', $stream);
        fclose($stream);
    }

    protected function genRels(): string
    {
        // phpcs:disable
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
    <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>
XML;
        // phpcs:enable
    }

    protected function genAppXml(): string
    {
        // phpcs:disable
        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"
    xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
    <TotalTime>0</TotalTime>
    <Company></Company>
</Properties>
XML;
        // phpcs:enable
    }

    protected function genCoreXml(): string
    {
        $created = gmdate('Y-m-d\TH:i:s\Z');
        $title = $this->title ?? "";
        $subject = $this->subject ?? "";
        $creator = $this->creator ?? "";
        $keywords = $this->keywords ?? "";
        $description = $this->description ?? "";
        $category = $this->category ?? "";
        $language = $this->language ?? "en-US";

        // phpcs:disable
        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"
    xmlns:dc="http://purl.org/dc/elements/1.1/"
    xmlns:dcmitype="http://purl.org/dc/dcmitype/"
    xmlns:dcterms="http://purl.org/dc/terms/"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <dcterms:created xsi:type="dcterms:W3CDTF">$created</dcterms:modified>
    <dc:title>$title</dc:title>
    <dc:subject>$subject</dc:subject>
    <dc:creator>$creator</dc:creator>
    <cp:keywords>$keywords</cp:keywords>
    <dc:description>$description</dc:description>
    <cp:category>$category</cp:category>
    <dc:language>$language</dc:language>
    <cp:revision>0</cp:revision>
</cp:coreProperties>
XML;
        // phpcs:enable
    }

    protected function genStyles(): string
    {
        // phpcs:disable
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<numFmts count="1">
    <numFmt numFmtId="164" formatCode="GENERAL" />
</numFmts>
<fonts count="1">
    <font><name val="Arial"/><family val="2"/><sz val="10"/></font>
</fonts>
<fills count="2">
    <fill><patternFill patternType="none" /></fill>
    <fill><patternFill patternType="gray125" /></fill>
</fills>
<borders count="1">
<border><left/><right/><top/><bottom/><diagonal/></border>
</borders>
<cellStyleXfs count="1">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" />
</cellStyleXfs>
<cellXfs count="1">
    <xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="0" numFmtId="164" xfId="0">
        <alignment horizontal="general" vertical="bottom" textRotation="0" wrapText="false" indent="0" shrinkToFit="false"/>
        <protection locked="true" hidden="false"/>
    </xf>
</cellXfs>
<cellStyles count="1">
    <cellStyle name="Normal" xfId="0" builtinId="0"/>
</cellStyles>
</styleSheet>
XML;
        // phpcs:enable
    }

    protected function genWorkbook(): string
    {
        // phpcs:disable
        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
    xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <fileVersion appName="LeKoala\SpreadCompat"/>
    <sheets>
        <sheet name="Sheet1" sheetId="1" state="visible" r:id="rId2"/>
    </sheets>
</workbook>
XML;
        // phpcs:enable
    }

    /**
     * @return resource
     */
    protected function genWorksheet(iterable $data)
    {
        $tempStream = SpreadCompat::getMaxMemTempStream();
        $r = 0;

        // Since we don't know in advance, let's have the max
        $MAX_ROW = 1048576;
        $MAX_COL = 16384;

        $maxCell = SpreadCompat::excelCell($MAX_ROW, $MAX_COL);

        $header = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
    xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <dimension ref="A1:{$maxCell}"/>
    <cols>
        <col collapsed="false" hidden="false" max="1024" min="1" style="0" customWidth="false" width="11.5"/>
    </cols>
    <sheetData>
XML;
        fwrite($tempStream, $header);

        $dataRow = [""];
        foreach ($data as $dataRow) {
            $c = "";
            $i = 0;
            foreach ($dataRow as $k => $value) {
                $cn = SpreadCompat::excelCell($r, $i);

                if (!is_scalar($value) || $value === '') {
                    $c .= '<c r="' . $cn . '"/>';
                } else {
                    if (
                        !is_string($value)
                        || $value == '0'
                        || ($value[0] != '0' && ctype_digit($value))
                        || preg_match("/^\-?(0|[1-9][0-9]*)(\.[0-9]+)?$/", $value)
                    ) {
                        $c .= '<c r="' . $cn . '" t="n"><v>' . $value . '</v></c>'; //int,float,currency
                    } else {
                        $c .= '<c r="' . $cn . '" t="inlineStr"><is><t>' . self::esc($value) . '</t></is></c>';
                    }
                }
                $c .= "\r\n";
                $i++;
            }

            $r++;
            fwrite($tempStream, "<row r=\"$r\">$c</row>\r\n");
        }


        // $totalCols = count($dataRow);
        // $maxLetter = SpreadCompat::getLetter($totalCols);
        // $maxRow = $r;

        $footer = <<<XML
    </sheetData>
</worksheet>
XML;
        fwrite($tempStream, $footer);
        return $tempStream;
    }

    protected function genWorkbookRels(): string
    {
        // phpcs:disable
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>
XML;
        // phpcs:enable
    }

    protected function genContentTypes(): string
    {
        // phpcs:disable
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Override PartName="/_rels/.rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Override PartName="/xl/_rels/workbook.xml.rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
    <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
    <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
</Types>
XML;
        // phpcs:enable
    }

    protected static function esc(string $str): string
    {
        return str_replace(['&', '<', '>', "\x00", "\x03", "\x0B"], ['&amp;', '&lt;', '&gt;', '', '', ''], $str);
    }

    public function writeFile(
        iterable $data,
        string $filename,
        ...$opts
    ): bool {
        $this->configure(...$opts);

        $stream = SpreadCompat::getOutputStream($filename);
        $zip = new ZipStream(
            sendHttpHeaders: false,
            outputStream: $stream,
            outputName: $filename,
        );
        $this->write($zip, $data);
        $size = $zip->finish();
        return fclose($stream);
    }

    public function output(
        iterable $data,
        string $filename,
        ...$opts
    ): void {
        $this->configure(...$opts);

        $zip = new ZipStream(
            contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            sendHttpHeaders: true,
            outputName: $filename,
        );
        $this->write($zip, $data);
        $zip->finish();
    }
}
