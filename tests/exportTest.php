<?php

namespace tests\eLife;

use PHPUnit\Framework\TestCase;

class exportTest extends TestCase
{
    /**
     * @test
     * @dataProvider providerLineBreaks
     */
    public function it_will_preserve_line_breaks($raw_message, $expected)
    {
        $this->assertContains($expected, convert_raw_message_to_markdown($raw_message));
    }

    public function providerLineBreaks()
    {
        yield 'single line-break' => [
            "<strong>Comment on Version 2</strong>\nThe following sentence and citation to Bio-protocol (Müller and Münch, 2016) was added to the  Materials and methods:\n\nFinally, we tested whether the molecular tweezer inhibited semen-mediated infection enhancement, as described (Müller and Münch, 2016).\n\nThe following citation has been added to the Reference list:\nMüller, JA and Münch, J. (2016). Reporter assay for semen-mediated enhancement of HIV-1 infection. Bio-protocol 6(14): e1871. http://dx.doi.org/10.21769/BioProtoc.1871",
            "**Comment on Version 2**\nThe following sentence and citation",
        ];
    }

    /**
     * @test
     * @dataProvider providerLT
     */
    public function it_can_handle_lt($raw_message, $expected)
    {
        $this->assertContains($expected, convert_raw_message_to_markdown($raw_message));
    }

    public function providerLT()
    {
        yield 'just before integer' => [
            "That data qualities are excellent.  \n\nWe have used splicingcode table of <230,000 introns, which are 10-20% of the estimated human introns,  to analyze their datasets. We have identified about 4,500 fusion transcripts, from which the numbers of highly-recurrent fusion  have been identified.  One of the most intriguing loci is at the chromosome  15q24.  It has read-through fusion transcripts of SH3GL3|ADAMTSL3 and actively-inversion of ADAMTSL3|SH3GL3 if it is a somatic mutation,         or alternative-spliced if it is germline-inherited. We have identified 17 ADAMTSL3|SH3GL3 isoforms or inversion types.  They are also detected in some of their controls.  The following fusion transcripts in the table are some of the highly-recurrent fusion transcripts. If you are interested in some of these data, you can go to http://splicingcodes.com to request data described in detail. ",
            "We have used splicingcode table of <230,000 introns",
        ];
    }
}
