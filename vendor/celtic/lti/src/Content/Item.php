<?php

namespace ceLTIc\LTI\Content;

use ceLTIc\LTI\Util;

/**
 * Class to represent a content-item object
 *
 * @author  Stephen P Vickers <stephen@spvsoftwareproducts.com>
 * @copyright  SPV Software Products
 * @license  http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License, version 3
 */
class Item
{

    /**
     * Type for link content-item.
     */
    const TYPE_LINK = 'link';

    /**
     * Type for LTI link content-item.
     */
    const TYPE_LTI_LINK = 'ltiResourceLink';

    /**
     * Type for file content-item.
     */
    const TYPE_FILE = 'file';

    /**
     * Type for HTML content-item.
     */
    const TYPE_HTML = 'html';

    /**
     * Type for image content-item.
     */
    const TYPE_IMAGE = 'image';

    /**
     * Media type for LTI launch links.
     */
    const LTI_LINK_MEDIA_TYPE = 'application/vnd.ims.lti.v1.ltilink';

    /**
     * Type of content-item.
     *
     * @var string|null $type
     */
    private $type = null;

    /**
     * ID of content-item.
     *
     * @var string|null $id
     */
    private $id = null;

    /**
     * Array of placement objects for content-item.
     *
     * @var array $placements
     */
    private $placements = array();

    /**
     * URL of content-item.
     *
     * @var string|null $url
     */
    private $url = null;

    /**
     * Media type of content-item.
     *
     * @var string|null $mediaType
     */
    private $mediaType = null;

    /**
     * Title of content-item.
     *
     * @var string|null $title
     */
    private $title = null;

    /**
     * Description of content-item.
     *
     * @var string|null $text
     */
    private $text = null;

    /**
     * Icon image object for content-item.
     *
     * @var Image|null $icon
     */
    private $icon = null;

    /**
     * Thumbnail image object for content-item.
     *
     * @var Image|null $thumbnail
     */
    private $thumbnail = null;

    /**
     * Class constructor.
     *
     * @param string $type Class type of content-item
     * @param Placement $placementAdvice  Placement object for item (optional)
     * @param string $id   URL of content-item (optional)
     */
    function __construct($type, $placementAdvice = null, $id = null)
    {
        $this->type = $type;
        if (!empty($placementAdvice)) {
            $this->placements[$placementAdvice->documentTarget] = $placementAdvice;
        }
        $this->id = $id;
    }

    /**
     * Set a URL value for the content-item.
     *
     * @param string $url  URL value
     */
    public function setUrl($url)
    {
        $this->url = $url;
    }

    /**
     * Set a media type value for the content-item.
     *
     * @param string $mediaType  Media type value
     */
    public function setMediaType($mediaType)
    {
        $this->mediaType = $mediaType;
    }

    /**
     * Set a title value for the content-item.
     *
     * @param string $title  Title value
     */
    public function setTitle($title)
    {
        $this->title = $title;
    }

    /**
     * Set a link text value for the content-item.
     *
     * @param string $text  Link text value
     */
    public function setText($text)
    {
        $this->text = $text;
    }

    /**
     * Add a placement for the content-item.
     *
     * @param Placement $placementAdvice  Placement advice object
     */
    public function addPlacementAdvice($placementAdvice)
    {
        if (!empty($placementAdvice)) {
            $this->placements[$placementAdvice->documentTarget] = $placementAdvice;
        }
    }

    /**
     * Set an icon image for the content-item.
     *
     * @param Image $icon  Icon image object
     */
    public function setIcon($icon)
    {
        $this->icon = $icon;
    }

    /**
     * Set a thumbnail image for the content-item.
     *
     * @param Image $thumbnail  Thumbnail image object
     */
    public function setThumbnail($thumbnail)
    {
        $this->thumbnail = $thumbnail;
    }

    /**
     * Wrap the content items to form a complete application/vnd.ims.lti.v1.contentitems+json media type instance.
     *
     * @param mixed $items  An array of content items or a single item
     * @param string $ltiVersion  LTI version in use
     *
     * @return string
     */
    public static function toJson($items, $ltiVersion = Util::LTI_VERSION1)
    {
        if (!is_array($items)) {
            $items = array($items);
        }
        if ($ltiVersion !== Util::LTI_VERSION1P3) {
            $obj = new \stdClass();
            $obj->{'@context'} = 'http://purl.imsglobal.org/ctx/lti/v1/ContentItem';
            $obj->{'@graph'} = array();
            foreach ($items as $item) {
                $obj->{'@graph'}[] = $item->toJsonldObject();
            }
        } else {
            $obj = array();
            foreach ($items as $item) {
                $obj[] = $item->toJsonObject();
            }
        }

        return json_encode($obj);
    }

    /**
     * Wrap the content items to form a complete application/vnd.ims.lti.v1.contentitems+json media type instance.
     *
     * @param object $items  A JSON object representing Content-Items
     *
     * @return array Array of Item objects
     */
    public static function fromJson($items)
    {
        $isJsonLd = isset($items->{'@graph'});
        if ($isJsonLd) {
            $items = $items->{'@graph'};
        }
        if (!is_array($items)) {
            $items = array($items);
        }
        $objs = array();
        foreach ($items as $item) {
            $obj = null;
            if (isset($item->{'@type'})) {
                switch ($item->{'@type'}) {
                    case 'ContentItem':
                        $obj = new Item('ContentItem');
                        break;
                    case 'LtiLinkItem':
                        $obj = new LtiLinkItem();
                        break;
                    case 'FileItem':
                        $obj = new FileItem();
                        break;
                }
            } elseif (isset($item->type)) {
                switch ($item->type) {
                    case self::TYPE_LINK:
                    case self::TYPE_HTML:
                    case self::TYPE_IMAGE:
                        $obj = new Item($item->type);
                        break;
                    case self::TYPE_LTI_LINK:
                        $obj = new LtiLinkItem();
                        break;
                    case self::TYPE_FILE:
                        $obj = new FileItem();
                        break;
                }
            }
            if ($obj) {
                $obj->fromJsonObject($item);
                $objs[] = $obj;
            }
        }

        return $objs;
    }

    protected function toJsonldObject()
    {
        $item = new \stdClass();
        if (!empty($this->id)) {
            $item->{'@id'} = $this->id;
        }
        if (!empty($this->type)) {
            if ($this->type === self::TYPE_LTI_LINK) {
                $item->{'@type'} = 'LtiLinkItem';
            } elseif ($this->type === self::TYPE_FILE) {
                $item->{'@type'} = 'FileItem';
            } else {
                $item->{'@type'} = 'ContentItem';
            }
        } else {
            $item->{'@type'} = 'ContentItem';
        }
        if (!empty($this->title)) {
            $item->title = $this->title;
        }
        if (!empty($this->text)) {
            $item->text = $this->text;
        }
        if (!empty($this->url)) {
            $item->url = $this->url;
        }
        if (!empty($this->mediaType)) {
            $item->mediaType = $this->mediaType;
        }
        if (!empty($this->placements)) {
            $placement = reset($this->placements);
            $obj = $placement->toJsonldObject();
            if (!empty($obj)) {
                $item->placementAdvice = $obj;
            }
        }
        if (!empty($this->icon)) {
            $item->icon = $this->icon->toJsonldObject();
        }
        if (!empty($this->thumbnail)) {
            $item->thumbnail = $this->thumbnail->toJsonldObject();
        }

        return $item;
    }

    protected function toJsonObject()
    {
        $item = new \stdClass();
        switch ($this->type) {
            case 'LtiLinkItem':
                $item->type = self::TYPE_LTI_LINK;
                break;
            case 'FileItem':
                $item->type = self::TYPE_FILE;
                break;
            case 'ContentItem':
                if (empty($this->url)) {
                    $item->type = self::TYPE_HTML;
                } elseif (!empty($this->mediaType) && (strpos($this->mediaType, 'image') === 0)) {
                    $item->type = self::TYPE_IMAGE;
                } else {
                    $item->type = self::TYPE_LINK;
                }
                break;
            default:
                $item->type = $this->type;
                break;
        }
        if (!empty($this->title)) {
            $item->title = $this->title;
        }
        if (!empty($this->text)) {
            $item->text = $this->text;
        }
        if (!empty($this->url)) {
            $item->url = $this->url;
        }
        foreach ($this->placements as $type => $placement) {
            switch ($type) {
                case Placement::TYPE_EMBED:
                case Placement::TYPE_IFRAME:
                case Placement::TYPE_WINDOW:
                    $obj = $placement->toJsonObject();
                    break;
                case Placement::TYPE_FRAME:
                    $obj = $placement->toJsonObject();
                    break;
                default:
                    $obj = null;
                    break;
            }
            if (!empty($obj)) {
                $item->{$type} = $obj;
            }
        }
        if (!empty($this->icon)) {
            $item->icon = $this->icon->toJsonObject();
        }
        if (!empty($this->thumbnail)) {
            $item->thumbnail = $this->thumbnail->toJsonObject();
        }

        return $item;
    }

    protected function fromJsonObject($item)
    {
        if (isset($item->{'@id'})) {
            $this->id = $item->{'@id'};
        }
        $placements = array();
        foreach (get_object_vars($item) as $name => $value) {
            switch ($name) {
                case 'title':
                case 'text':
                case 'url':
                case 'mediaType':
                    $this->{$name} = $item->{$name};
                    break;
                case 'placementAdvice':
                    $this->addPlacementAdvice(Placement::fromJsonObject($item->{$name}));
                    break;
                case 'embed':
                case 'window':
                case 'iframe':
                    $item->{$name}->documentTarget = $name;
                    $this->addPlacementAdvice(Placement::fromJsonObject($item->{$name}));
                    break;
                case 'icon':
                case 'thumbnail':
                    $this->{$name} = Image::fromJsonObject($item->{$name});
                    break;
            }
        }
    }

}
