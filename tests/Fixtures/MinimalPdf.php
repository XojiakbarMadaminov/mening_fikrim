<?php

namespace Tests\Fixtures;

/** Generated regression source, never represented as a real Lex.uz document. */
class MinimalPdf
{
    public static function bytes(): string
    {
        $stream = "BT /F1 10 Tf 50 760 Td (Normative regression fixture) Tj\n";
        for ($i = 1; $i <= 20; $i++) {
            $stream .= "0 -32 Td (Article $i. This normative text preserves every clause during local PDF extraction.) Tj\n";
        }
        $stream .= "ET\n";
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 600 800] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            5 => '<< /Length '.strlen($stream).">>\nstream\n".$stream.'endstream'];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= "$id 0 obj\n$object\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }
}
