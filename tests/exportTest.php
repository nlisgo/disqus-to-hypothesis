<?php

namespace tests\eLife;

use DateTimeImmutable;
use Error;
use PHPUnit\Framework\TestCase;

class exportTest extends TestCase
{
    /** @var DisqusItem[] */
    private $export = [];

    /**
     * @before
     */
    public function load_export_files()
    {
        $items = \GuzzleHttp\json_decode(file_get_contents(__DIR__.'/../export/export.json'), true);
        foreach ($items as $item) {
            $this->export[(int) preg_replace('/^disqus\-import:/', '', $item['id'])] = new DisqusItem($item);
        }

        ksort($this->export);
    }

    /**
     * @test
     */
    public function it_is_not_empty()
    {
        $this->assertNotEmpty($this->export);
    }

    /**
     * @test
     */
    public function it_has_a_first_entry()
    {
        $expected = 'I wish the team good luck in making important discoveries and reporting them back to us.';

        $actual = reset($this->export);
        $this->assertEquals($expected, $actual->getBody());
    }

    /**
     * @test
     */
    public function it_will_preserve_line_breaks()
    {
        $actual = $this->export[3300124537];
        $this->assertStringStartsWith("**Comment on Version 2**\nThe following sentence and citation", $actual->getBody());
    }

    /**
     * @test
     */
    public function it_can_handle_lt()
    {
        $actual = $this->export[2773314626];
        $this->assertContains('We have used splicingcode table of <230,000 introns', $actual->getBody());
    }
}

/**
 * @method string getCreated()
 * @method DateTimeImmutable getCreatedDate()
 * @method string getCreator()
 * @method string getEmail()
 * @method string getModified()
 * @method DateTimeImmutable getModifiedDate()
 * @method string getMotivation()
 * @method string getName()
 * @method string getTarget()
 * @method string getType()
 */
class DisqusItem
{
    private $item;

    public function __construct($item)
    {
        $this->item = $item;
    }

    public function getContext()
    {
        return $this->item['@context'];
    }

    public function getBody()
    {
        return $this->item['body'][0]['value'];
    }

    public function __call($name, $arguments)
    {
        if (preg_match('/^get(?P<key>[A-Z][a-z]*)(?P<date>Date)?$/', $name, $match)) {
            $key = strtolower($match['key']);
            if (isset($this->item[$key])) {
                $value = $this->item[$key];
                if (!empty($match['date'])) {
                    $value = new DateTimeImmutable($value);
                }
                return $value;
            }
        }

        throw new Error(sprintf('Call to undefined method %s::%s()', self::class, $name));
    }
}
