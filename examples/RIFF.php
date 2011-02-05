<?php
class RIFFException extends RuntimeException {}

abstract class RIFFChunk
{
    protected $id;
    protected $size;
    protected $data;

    public function __construct($source)
    {
        if (is_array($source)) {
            if (!array_key_exists('id', $source)
                || !is_string($source['id'])
                || self::checkId($source['id'])
            ) {
                $this->raiseError();
            }
            if (!array_key_exists('size', $source)
                || !is_int($source['size'])
            ) {
                $this->raiseError();
            }
            if (!array_key_exists('data', $source)
                || !is_string($source['data'])
                || strlen($source['data']) !== $source['size']
            ) {
                $this->raiseError();
            }
            $chunkInfo = $source;
        } else {
            $chunkInfo = $this->parseChunk($source);
            if (8 + $chunkInfo['size'] !== strlen($source)) {
                $this->raiseError();
            }
        }
        $this->id = $chunkInfo['id'];
        $this->size = $chunkInfo['size'];
        $this->data = $chunkInfo['data'];
    }

    public function getId()
    {
        return $this->id;
    }

    public function getSize()
    {
        return $this->size;
    }

    public function getData()
    {
        return $this->data;
    }

    public function getRawData()
    {
        return $this->data;
    }

    public function dump()
    {
        return self::pack($this->id, $this->size, $this->getRawData());
    }

    public function dumpToFile($filename)
    {
        file_put_contents($filename, $this->dump());
    }

    protected function parseChunk($source)
    {
        $length = strlen($source);
        if ($length < 9) {
            $this->raiseError();
        }

        $arr = unpack('V', substr($source, 4, 4));
        $size = $arr[1];
        if (8 + $size > $length) {
            $this->raiseError();
        }

        return array(
            'id' => substr($source, 0, 4),
            'size' => $size,
            'data' => substr($source, 8, $size),
        );
    }

    protected function raiseError(
        $message = "Not a valid RIFF structure.",
        $code = 0
    ) {
        throw new RIFFException($message, $code);
    }

    protected static function pack($id, $size, $data)
    {
        return $id . pack('V', $size) . $data;
    }

    protected static function checkId($id)
    {
        if (strlen($id) !== 4 || !preg_match('/^[0-9A-Za-z_ ]+$/', $id)) {
            throw new RIFFException("Not a valid RIFF ID.");
        }
    }
}

class RIFFBinaryChunk extends RIFFChunk
{
    public static function createFromBinary($id, $data)
    {
        $size = strlen($data);
        return new static(compact('id', 'size', 'data'));
    }
}

class RIFFStringChunk extends RIFFBinaryChunk
{
    public function __construct($source)
    {
        parent::__construct($source);
        if (strpos($this->data, chr(0)) !== strlen($this->data) - 1) {
            $this->raiseError();
        }
    }

    public static function createFromString($id, $str)
    {
        return static::createFromBinary($id, $str . chr(0));
    }

    public function getData()
    {
        return substr($this->data, 0, -1);
    }
}

abstract class RIFFListChunk extends RIFFChunk
{
    protected $chunks = array();

    public function __construct($source)
    {
        parent::__construct($source);
        $type = substr($this->data, 0, 4);
        self::checkId($type);
        $this->parseSubChunks(substr($this->data, 4));
        $this->data = $type;
    }

    public function getType()
    {
        return $this->data;
    }

    public function getData()
    {
        return $this->chunks;
    }

    public function getRawData()
    {
        $data = $this->data;
        foreach ($this->chunks as $chunk) {
            $data .= $chunk->dump();
        }
        return $data;
    }

    protected function getChunk($tag)
    {
        if (array_key_exists($tag, $this->chunks)) {
            return $this->chunks[$tag];
        }
        return null;
    }

    protected function getChunkData($tag)
    {
        $chunk = $this->getChunk($tag);
        if (is_null($chunk)) {
            return null;
        }
        return $chunk->getData();
    }

    protected function parseSubChunks($data)
    {
        $pos = 0;
        $eos = strlen($data);
        while ($pos < $eos) {
            $chunkInfo = $this->parseChunk(substr($data, $pos));
            $this->chunks[$chunkInfo['id']] = $this->newChunk($chunkInfo);
            $pos += 8 + $chunkInfo['size'];
        }
    }

    abstract protected function newChunk(array $chunkInfo);
}

abstract class RIFFMutableListChunk extends RIFFListChunk
{
    protected function setChunk(RIFFChunk $chunk)
    {
        $tag = $chunk->getId();
        $oldChunk = $this->getChunk($tag);
        if (!is_null($oldChunk)) {
            $this->size -= 8 + $oldChunk->getSize();
        }
        $this->chunks[$tag] = $chunk;
        $this->size += 8 + $chunk->getSize();
    }

    protected function deleteChunk($tag)
    {
        $chunk = $this->getChunk($tag);
        if (!is_null($chunk)) {
            $this->length -= 8 + $chunk->getSize();
            unset($this->chunks[$tag]);
        }
    }
}

abstract class RIFF extends RIFFMutableListChunk
{
    const TAG_RIFF = 'RIFF';
    const TAG_ICMT = 'ICMT';
    const TAG_ICOP = 'ICOP';
    const TAG_IART = 'IART';
    const TAG_INAM = 'INAM';

    public static function createFromFile($filename)
    {
        $className = get_called_class();
        return new $className(file_get_contents($filename));
    }

    public function __construct($source)
    {
        parent::__construct($source);
        if ($this->id !== self::TAG_RIFF) {
            $this->raiseError();
        }
    }
}

class WebP extends RIFF
{
    const TAG_WEBP = 'WEBP';
    const TAG_VP8  = 'VP8 ';

    public static function createFromVP8Image($data)
    {
        $size = strlen($data);
        $webp = self::pack(RIFF::TAG_RIFF, $size + 12, self::TAG_WEBP)
              . self::pack(self::TAG_VP8, $size, $data);
        return new WebP($webp);
    }

    public function __construct($source)
    {
        parent::__construct($source);
        if ($this->getType() !== self::TAG_WEBP) {
            $this->raiseError();
        }
        if (key($this->chunks) !== self::TAG_VP8) {
            $this->raiseError();
        }
    }

    protected function newChunk(array $chunkInfo)
    {
        switch ($chunkInfo['id']) {
            case self::TAG_VP8;
                return new RIFFBinaryChunk($chunkInfo);
            case RIFF::TAG_ICMT:
            case RIFF::TAG_ICOP:
            case RIFF::TAG_IART:
            case RIFF::TAG_INAM:
                return new RIFFStringChunk($chunkInfo);
        }
        $this->raiseError();
    }

    public function getVP8Image()
    {
        return $this->getChunkData(RIFF::TAG_VP8);
    }

    public function getComment()
    {
        return $this->getChunkData(RIFF::TAG_ICMT);
    }

    public function getCopyright()
    {
        return $this->getChunkData(RIFF::TAG_ICOP);
    }

    public function getArtist()
    {
        return $this->getChunkData(RIFF::TAG_IART);
    }

    public function getTitle()
    {
        return $this->getChunkData(RIFF::TAG_INAM);
    }

    public function setComment($str)
    {
        $this->setMetadata(RIFF::TAG_ICMT, $str);
    }

    public function setCopyright($str)
    {
        $this->setMetadata(RIFF::TAG_ICOP, $str);
    }

    public function setArtist($str)
    {
        $this->setMetadata(RIFF::TAG_IART, $str);
    }

    public function setTitle($str)
    {
        $this->setMetadata(RIFF::TAG_INAM, $str);
    }

    public function clearMetadata()
    {
        $this->deleteChunk(RIFF::TAG_ICMT);
        $this->deleteChunk(RIFF::TAG_ICOP);
        $this->deleteChunk(RIFF::TAG_IART);
        $this->deleteChunk(RIFF::TAG_INAM);
    }

    private function setMetadata($tag, $str)
    {
        if (is_null($str)) {
            $this->deleteChunk($tag);
        } else {
            $this->setChunk(RIFFStringChunk::createFromString($tag, $str));
        }
    }
}

function webp_read_metadata($filename)
{
    $metadata = array();
    $webp = WebP::createFromFile($filename);
    foreach ($webp->getData() as $tag => $chunk) {
        if ($tag !== WebP::TAG_VP8) {
            $metadata[$tag] = $chunk->getData();
        }
    }
    return $metadata;
}
