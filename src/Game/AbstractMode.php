<?php

namespace ChessServer\Game;

use Chess\FenToBoard;
use Chess\Function\StandardFunction;
use Chess\Heuristics\FenHeuristics;
use Chess\Movetext\NagMovetext;
use Chess\Tutor\FenExplanation;
use Chess\UciEngine\Stockfish;
use Chess\Variant\Capablanca\Board as CapablancaBoard;
use Chess\Variant\Chess960\Board as Chess960Board;
use Chess\Variant\Classical\Board as ClassicalBoard;
use ChessServer\Game\Game;
use ChessServer\Command\HeuristicsCommand;
use ChessServer\Command\LegalCommand;
use ChessServer\Command\PlayLanCommand;
use ChessServer\Command\StockfishCommand;
use ChessServer\Command\StockfishEvalCommand;
use ChessServer\Command\TutorFenCommand;
use ChessServer\Command\UndoCommand;
use ChessServer\Exception\InternalErrorException;

abstract class AbstractMode
{
    protected $game;

    protected $resourceIds;

    protected $hash;

    public function __construct(Game $game, array $resourceIds)
    {
        $this->game = $game;
        $this->resourceIds = $resourceIds;
    }

    public function getGame()
    {
        return $this->game;
    }

    public function setGame(Game $game)
    {
        $this->game = $game;

        return $this;
    }

    public function getResourceIds(): array
    {
        return $this->resourceIds;
    }

    public function setResourceIds(array $resourceIds)
    {
        $this->resourceIds = $resourceIds;

        return $this;
    }

    public function getHash()
    {
        return $this->hash;
    }

    public function res($argv, $cmd)
    {
        try {
            switch (get_class($cmd)) {
                case HeuristicsCommand::class:
                    if (
                        $argv[2] === Game::VARIANT_CAPABLANCA ||
                        $argv[2] === Game::VARIANT_CAPABLANCA_FISCHER
                    ) {
                        $board = FenToBoard::create($argv[1], new CapablancaBoard());
                    } else {
                        $board = FenToBoard::create($argv[1], new ClassicalBoard());
                    }
                    return [
                        $cmd->name => [
                            'names' => (new StandardFunction())->names(),
                            'balance' => (new FenHeuristics($board))->getBalance(),
                        ],
                    ];
                case LegalCommand::class:
                    return [
                        $cmd->name => $this->game->getBoard()->legal($argv[1]),
                    ];
                case PlayLanCommand::class:
                    $this->game->playLan($argv[1], $argv[2]);
                    return [
                        $cmd->name => [
                          ... (array) $this->game->state(),
                          'variant' =>  $this->game->getVariant(),
                        ],
                    ];
                case StockfishCommand::class:
                    if (!$this->game->state()->isMate && !$this->game->state()->isStalemate) {
                        $options = json_decode(stripslashes($argv[1]), true);
                        $params = json_decode(stripslashes($argv[2]), true);
                        $ai = $this->game->ai($options, $params);
                        if ($ai->move) {
                            $this->game->play($this->game->state()->turn, $ai->move);
                        }
                    }
                    return [
                        $cmd->name => [
                          ... (array) $this->game->state(),
                          'variant' =>  $this->game->getVariant(),
                        ],
                    ];
                case StockfishEvalCommand::class:
                    if (
                        $argv[2] === ClassicalBoard::VARIANT ||
                        $argv[2] === Chess960Board::VARIANT
                    ) {
                        $board = FenToBoard::create($argv[1]);
                        $stockfish = new Stockfish($board);
                        $nag = $stockfish->evalNag($board->toFen(), 'Final');
                        return [
                            $cmd->name => NagMovetext::glyph($nag),
                        ];
                    }

                    return [
                        $cmd->name => null,
                    ];
                case TutorFenCommand::class:
                    if (
                        $argv[2] === Game::VARIANT_CAPABLANCA ||
                        $argv[2] === Game::VARIANT_CAPABLANCA_FISCHER
                    ) {
                        $board = FenToBoard::create($argv[1], new CapablancaBoard());
                    } else {
                        $board = FenToBoard::create($argv[1], new ClassicalBoard());
                    }
                    $paragraph = (new FenExplanation($board, $isEvaluated = true))->getParagraph();
                    return [
                        $cmd->name => implode(' ', $paragraph),
                    ];
                case UndoCommand::class:
                    $board = $this->game->getBoard()->undo();
                    $this->game->setBoard($board);
                    return [
                        $cmd->name => [
                          ... (array) $this->game->state(),
                          'variant' =>  $this->game->getVariant(),
                        ],
                    ];
                default:
                    return null;
            }
        } catch (\Exception $e) {
            throw new InternalErrorException();
        }
    }
}
