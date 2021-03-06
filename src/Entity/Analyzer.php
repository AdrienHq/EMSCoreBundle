<?php

namespace EMS\CoreBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use EMS\CoreBundle\Entity\Helper\JsonClass;
use EMS\CoreBundle\Entity\Helper\JsonDeserializer;
use EMS\CoreBundle\Form\Field\AnalyzerOptionsType;

/**
 * Analyzer
 *
 * @ORM\Table(name="analyzer")
 * @ORM\Entity(repositoryClass="EMS\CoreBundle\Repository\AnalyzerRepository")
 * @ORM\HasLifecycleCallbacks()
 */
class Analyzer extends JsonDeserializer implements \JsonSerializable
{
    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;
    
    /**
     * @var string
     *
     * @ORM\Column(name="name", type="string", length=255, unique=true)
     */
    protected $name;
    
    /**
     * @var bool
     *
     * @ORM\Column(name="dirty", type="boolean")
     */
    protected $dirty;
    
    /**
     * @var string
     *
     * @ORM\Column(name="label", type="string", length=255)
     */
    protected $label;
    
    /**
     * @var array
     *
     * @ORM\Column(name="options", type="json_array")
     */
    protected $options;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="created", type="datetime")
     */
    protected $created;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="modified", type="datetime")
     */
    protected $modified;
    
    /**
     * @var int
     *
     * @ORM\Column(name="order_key", type="integer", nullable=true)
     */
    protected $orderKey;
    
    
    public function __construct()
    {
        $this->options = [];
        $this->dirty = true;
    }
    
    /**
     * @ORM\PrePersist
     * @ORM\PreUpdate
     */
    public function updateModified()
    {
        $this->modified = new \DateTime();
        if (!isset($this->created)) {
            $this->created = $this->modified;
        }
    }


    /**
     * Get id
     *
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }
    
    /**
     * Set name
     *
     * @param string $name
     *
     * @return Analyzer
     */
    public function setName($name)
    {
        $this->name = $name;
        
        return $this;
    }
    
    /**
     * Get name
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Set options
     *
     * @param array $options
     *
     * @return Analyzer
     */
    public function setOptions($options)
    {
        $this->options = $options;
        
        
        foreach ($this->options as $key => $data) {
            if ($key != 'type' and !in_array($key, AnalyzerOptionsType::FIELDS_BY_TYPE[$this->options['type']])) {
                unset($this->options[$key]);
            } else if ($this->options[$key] === null) {
                unset($this->options[$key]);
            }
        }
        return $this;
    }

    /**
     * Get options
     *
     * @return array
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * Set created
     *
     * @param \DateTime $created
     *
     * @return Analyzer
     */
    public function setCreated($created)
    {
        $this->created = $created;

        return $this;
    }

    /**
     * Get created
     *
     * @return \DateTime
     */
    public function getCreated()
    {
        return $this->created;
    }

    /**
     * Set modified
     *
     * @param \DateTime $modified
     *
     * @return Analyzer
     */
    public function setModified($modified)
    {
        $this->modified = $modified;

        return $this;
    }

    /**
     * Get modified
     *
     * @return \DateTime
     */
    public function getModified()
    {
        return $this->modified;
    }
    
    /**
     * Set dirty
     *
     * @param boolean $dirty
     *
     * @return Analyzer
     */
    public function setDirty($dirty)
    {
        $this->dirty = $dirty;
        
        return $this;
    }
    
    /**
     * Get dirty
     *
     * @return boolean
     */
    public function getDirty()
    {
        return $this->dirty;
    }
    
    /**
     * Set label
     *
     * @param string $label
     *
     * @return Analyzer
     */
    public function setLabel($label)
    {
        $this->label = $label;
        
        return $this;
    }
    
    /**
     * Get label
     *
     * @return string
     */
    public function getLabel()
    {
        return $this->label;
    }
    
    /**
     * Set orderKey
     *
     * @param integer $orderKey
     *
     * @return Analyzer
     */
    public function setOrderKey($orderKey)
    {
        $this->orderKey = $orderKey;
        
        return $this;
    }
    
    /**
     * Get orderKey
     *
     * @return integer
     */
    public function getOrderKey()
    {
        return $this->orderKey;
    }

    /**
     * Specify data which should be serialized to JSON
     * @link https://php.net/manual/en/jsonserializable.jsonserialize.php
     * @return mixed data which can be serialized by <b>json_encode</b>,
     * which is a value of any type other than a resource.
     * @since 5.4.0
     */
    public function jsonSerialize()
    {
        $json = new JsonClass(get_object_vars($this), __CLASS__);
        $json->removeProperty('id');

        return $json;
    }
}
