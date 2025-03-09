<?php

namespace ARiddlestone\LagoonKaleidoscope;

class Game
{
    /**
     * @var Tile[]
     */
    protected $tiles = [];

    /**
     * @var Tile[]
     */
    protected $board = [];

    protected $testCount = 0;

    public function __construct()
    {
        foreach (require './tiles.php' as $tileConfig) {
            $this->tiles[] = new Tile($tileConfig);
        }
    }

    public function solve()
    {
        /** @var int $n Where are we in the sequence? */
        $p = count($this->board);

        if($p >= count($this->tiles)) {
            return true;
        }

        foreach (
            array_filter(
                $this->tiles,
                function (Tile $t) {
                    return !in_array($t, $this->board);
                }
            ) as $tile
        ) {
            $this->board[$p] = $tile;
            for ($r = 0; $r < 4; $r++) {
                if ($this->test()) {
                    echo sprintf("[%s]\n", implode(', ', $this->board));
                    if ($this->solve()) {
                        return true;
                    }
                }
                $tile->rotateCW();
            }
            unset($this->board[$p]);
        }

        return false;
    }

    /**
     * @return Tile[]
     */
    public function getBoard()
    {
        return $this->board;
    }

    protected function test()
    {
        $this->testCount++;
        for ($x = 0; $x < 4; $x++) {
            for ($y = 0; $y < 4; $y++) {
                $p = $x + 4 * $y;

                if (empty($this->board[$p])) {
                    continue;
                }

                // east border
                if ($x < 3 && !empty($this->board[$p2 = $p + 1])) {
                    if ($this->board[$p]->getPattern(Tile::ROTATION_EAST) !== $this->board[$p2]->getPattern(
                            Tile::ROTATION_WEST
                        )
                    ) {
                        return false;
                    }
                }

                // south border
                if ($y < 3 && !empty($this->board[$p2 = $p + 4])) {
                    if ($this->board[$p]->getPattern(Tile::ROTATION_SOUTH) !== $this->board[$p2]->getPattern(
                            Tile::ROTATION_NORTH
                        )
                    ) {
                        return false;
                    }
                }
            }
        }
        return true;
    }

    /**
     * @return int
     */
    public function getTestCount()
    {
        return $this->testCount;
    }
}
