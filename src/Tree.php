<?php

namespace Ikwilkoffie;

use Ikwilkoffie\Tree\Exception\InvalidDatatypeException;
use Ikwilkoffie\Tree\Exception\InvalidParentException;
use Ikwilkoffie\Tree\Node;
use Illuminate\Support\ServiceProvider;

/**
 * Builds and gives access to a tree of nodes which is constructed thru nodes' parent node ID references.
 *
 * @author  Carsten Bluem <carsten@bluem.net>
 * @license http://www.opensource.org/licenses/bsd-license.php BSD 2-Clause License
 */
class Tree implements \JsonSerializable
{
    /**
     * The application instance.
     *
     * @var \Illuminate\Contracts\Foundation\Application
     */
    protected $app;

    /**
     * API version (will always be in sync with first digit of release version number).
     *
     * @var int
     */
    const API = 2;

    /**
     * @var int|float|string
     */
    protected $rootId = 0;

    /**
     * @var string
     */
    protected $idKey = 'id';

    /**
     * @var string
     */
    protected $parentKey = 'parent';

    /**
     * @var Node[]
     */
    protected $nodes;

    /**
     * @param array|\Traversable $data    The data for the tree (iterable)
     * @param array              $options 0 or more of the following keys: "rootId" (ID of the root node, defaults to 0), "id"
     *                                    (name of the ID field / array key, defaults to "id"), "parent", (name of the parent
     *                                    ID field / array key, defaults to "parent")
     *
     * @throws \Ikwilkoffie\Tree\Exception\InvalidParentException
     * @throws \Ikwilkoffie\Tree\Exception\InvalidDatatypeException
     * @throws \InvalidArgumentException
     */
    public function __construct()
    {

    }

    public function setOptions($options)
    {
        $options = array_change_key_case($options, CASE_LOWER);

        if (array_key_exists('rootid', $options)) {
            if ($options['rootid'] !== null && !\is_scalar($options['rootid'])) {
                throw new \InvalidArgumentException('Option “rootid” must be a scalar');
            }
            $this->rootId = $options['rootid'];
        }

        if (!empty($options['id'])) {
            if (!\is_string($options['id'])) {
                throw new \InvalidArgumentException('Option “id” must be a string');
            }
            $this->idKey = $options['id'];
        }

        if (!empty($options['parent'])) {
            if (!\is_string($options['parent'])) {
                throw new \InvalidArgumentException('Option “parent” must be a string');
            }
            $this->parentKey = $options['parent'];
        }
    }

    /**
     * @param array $data
     *
     * @throws \Ikwilkoffie\Tree\Exception\InvalidParentException
     * @throws \Ikwilkoffie\Tree\Exception\InvalidDatatypeException
     */
    public function rebuildWithData($data)
    {
        $this->build($data);
    }

    /**
     * Returns a flat, sorted array of all node objects in the tree.
     *
     * @return Node[] Nodes, sorted as if the tree was hierarchical,
     *                i.e.: the first level 1 item, then the children of
     *                the first level 1 item (and their children), then
     *                the second level 1 item and so on.
     */
    public function getNodes()
    {
        $nodes = [];
        foreach ($this->nodes[$this->rootId]->getDescendants() as $subnode) {
            $nodes[] = $subnode;
        }

        return $nodes;
    }

    /**
     * Returns a single node from the tree, identified by its ID.
     *
     * @param int|string $id Node ID
     *
     * @throws \InvalidArgumentException
     *
     * @return Node
     */
    public function getNodeById($id)
    {
        if (empty($this->nodes[$id])) {
            throw new \InvalidArgumentException("Invalid node primary key $id");
        }

        return $this->nodes[$id];
    }

    /**
     * Returns an array of all nodes in the root level.
     *
     * @return Node[] Nodes in the correct order
     */
    public function getRootNodes()
    {
        return $this->nodes[$this->rootId]->getChildren();
    }

    /**
     * Returns the first node for which a specific property's values of all ancestors
     * and the node are equal to the values in the given argument.
     *
     * Example: If nodes have property "name", and on the root level there is a node with
     * name "A" which has a child with name "B" which has a child which has node "C", you
     * would get the latter one by invoking getNodeByValuePath('name', ['A', 'B', 'C']).
     * Comparison is case-sensitive and type-safe.
     *
     * @param string $name
     * @param array  $search
     *
     * @return Node|null
     */
    public function getNodeByValuePath($name, $search)
    {
        $findNested = function ($nodes, $tokens) use ($name, &$findNested) {
            $token = array_shift($tokens);
            foreach ($nodes as $node) {
                $nodeName = $node->get($name);
                if ($nodeName === $token) {
                    // Match
                    if (\count($tokens)) {
                        // Search next level
                        return $findNested($node->getChildren(), $tokens);
                    }

                    // We found the node we were looking for
                    return $node;
                }
            }

            return null;
        };

        return $findNested($this->getRootNodes(), $search);
    }

    /**
     * Core method for creating the tree.
     *
     * @param array|\Traversable $data The data from which to generate the tree
     *
     * @throws \Ikwilkoffie\Tree\Exception\InvalidParentException
     * @throws InvalidDatatypeException
     */
    public function build($data)
    {
        if (!\is_array($data) && !($data instanceof \Traversable)) {
            throw new InvalidDatatypeException('Data must be an iterable (array or implement Traversable)');
        }

        $this->nodes = [];
        $children = [];

        // Check for non existent parents
        foreach ($data as $i => $item) {
            if(!in_array($item['parent_category_id'], array_column($data, 'id'))) { // search value in the array
                $data[$i]['parent_category_id'] = '';
            }
        }

        // Create the root node
        $this->nodes[$this->rootId] = $this->createNode($this->rootId, null, []);

        foreach ($data as $row) {
            if ($row instanceof \Iterator) {
                $row = iterator_to_array($row);
            }

            $this->nodes[$row[$this->idKey]] = $this->createNode(
                $row[$this->idKey],
                $row[$this->parentKey],
                $row
            );

            if (empty($children[$row[$this->parentKey]])) {
                $children[$row[$this->parentKey]] = [$row[$this->idKey]];
            } else {
                $children[$row[$this->parentKey]][] = $row[$this->idKey];
            }
        }

        foreach ($children as $pid => $childIds) {
            foreach ($childIds as $id) {
                if ((string) $pid === (string) $id) {
                    throw new InvalidParentException(
                        "Node with ID $id references its own ID as parent ID"
                    );
                }
                if (isset($this->nodes[$pid])) {
                    $this->nodes[$pid]->addChild($this->nodes[$id]);
                } else {
                    throw new InvalidParentException(
                        "Node with ID $id points to non-existent parent with ID $pid"
                    );
                }
            }
        }
    }

    /**
     * Returns a textual representation of the tree.
     *
     * @return string
     */
    public function __toString()
    {
        $str = [];
        foreach ($this->getNodes() as $node) {
            $indent1st = str_repeat('  ', $node->getLevel() - 1).'- ';
            $indent = str_repeat('  ', ($node->getLevel() - 1) + 2);
            $node = (string) $node;
            $str[] = $indent1st.str_replace("\n", "$indent\n  ", $node);
        }

        return implode("\n", $str);
    }

    /**
     * @return array
     */
    public function jsonSerialize()
    {
        return $this->getNodes();
    }

    /**
     * Creates and returns a node with the given properties.
     *
     * Can be overridden by subclasses to use a Node subclass for nodes.
     *
     * @param string|int $id
     * @param string|int $parent
     * @param array      $properties
     *
     * @return Node
     */
    protected function createNode($id, $parent, $properties)
    {
        return new Node($id, $parent, $properties);
    }
}
