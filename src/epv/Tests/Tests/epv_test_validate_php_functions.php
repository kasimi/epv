<?php
/**
 *
 * @package EPV
 * @copyright (c) 2014 phpBB Group
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 *
 */
namespace epv\Tests\Tests;


use epv\Files\FileInterface;
use epv\Files\Type\LangFile;
use epv\Files\Type\PHPFileInterface;
use epv\Output\Messages;
use epv\Output\OutputInterface;
use epv\Tests\BaseTest;
use epv\Tests\Exception\TestException;
use PhpParser\Error;
use PhpParser\Lexer\Emulative;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\Cast\Int;
use PhpParser\Node\Expr\Exit_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Parser;

class epv_test_validate_php_functions extends BaseTest {
    private $parser;
    private $file;


    public function __construct($debug, OutputInterface $output, $basedir)
    {
        parent::__construct($debug, $output, $basedir);

        $this->fileTypeFull = Type::TYPE_PHP;
        $this->parser = new Parser(new Emulative());
    }

    public function validateFile(FileInterface $file)
    {
        if (!$file instanceof PHPFileInterface)
        {
            throw new TestException("This tests except a service type, but got something else?");
        }
        $this->validate($file);
    }

    /**
     * Do the actual validation of the service file.
     * @param PHPFileInterface $file
     */
    private function validate(PHPFileInterface $file)
    {
        $this->output->writelnIfDebug("Trying to parse file: " . $file->getFilename());

        $this->file = $file;
        $this->in_phpbb = false;

        try
        {
            $stmt = $this->parser->parse($file->getFile());

            if ($file instanceof LangFile)
            {
                // Language files are just 1 array with data.
                // Do a separate test.
                $this->parseLangNodes($stmt);
            }
            else
            {
                $this->parseNodes($stmt);
            }

            if (!$this->in_phpbb)
            {
                $ok = true;
                // Lets see if there is just a namespace + class
                if (sizeof($stmt) == 1 && $stmt[0] instanceof Namespace_)
                {
                    foreach ($stmt[0]->stmts as $st)
                    {
                        if ($st instanceof Class_ || $st instanceof Interface_ || $st instanceof Use_)
                        {
                            continue;
                        }
                        $ok = false;
                    }
                }
                else
                {
                    $ok = false;
                }
                $dir = str_replace($this->basedir, '', $file->getFilename());
                $dir = explode("/", $dir);

                if ($dir[0] == 'test' || $dir[0] == 'tests')
                {
                    // We skip tests.
                    $this->output->writelnIfDebug(sprintf("Skipped %s because of test file.", $file->getFilename()));
                    $ok = true;
                }

                if (!$ok)
                {
                    $this->addMessage(Messages::WARNING, "IN_PHPBB is not defined");
                }
                else
                {
                    $this->output->writelnIfDebug(sprintf("Didn't find IN_PHPBB, but file (%s) only contains classes or interfaces", $file->getFilename()));
                }
            }
        }
        catch (Error $e)// Catch PhpParser error.
        {
            Messages::addMessage(Messages::FATAL, "PHP parse error in file " . $file->getFilename() . '. Message: ' . $e->getMessage());
        }
    }

    /**
     * Validate the structure of a php file.
     * @param array $nodes
     * @internal param array $node
     */
    private function parseNodes(array $nodes)
    {
        if (!($nodes[0] instanceof Namespace_))
        {
            foreach ($nodes as $node)
            {
                // Check if there is a class.
                // If there is a class, there should be a namespace.
            }

            $this->parseNode($nodes);

            return;
        }
        else
        {
            $this->parseNodes($nodes[0]->stmts);

            if (sizeof($nodes) > 1)
            {
                $this->addMessage(Messages::WARNING, "Besides the namespace, there should be no other statements in your fimle");
            }
        }

    }


    private $in_phpbb = false;
    /**
     * Validate the structure in a namespace, or in the full file if it is a non namespaced file.
     * @param array $nodes
     */
    private function parseNode(array $nodes)
    {
        foreach ($nodes as $node)
        {
            if ($node instanceof If_ && !$this->in_phpbb)
            {
                $this->checkInDefined($node);
                if ($this->in_phpbb)
                {
                    // IN_PHPBB was found, we continue.
                    continue;
                }
            }

            if (sizeof($node->stmts))
            {
                $this->parseNode($node->stmts);
            }
        }
    }

    /**
     * Check if the current node checks for IN_PHPBB, and
     * exits if it isnt defined.
     *
     * If IN_PHPBB is found, but there is no exit as first statement, it will not set IN_PHPBB, but will add a notice
     * instead for the user.  The other nodes will be send back to parseNode.
     *
     * @param If_ $node if node that checks possible for IN_PHPBB
     */
    private function checkInDefined(If_ $node)
    {
        $cond = $node->cond;

        if ($cond instanceof BooleanNot && $cond->expr instanceof FuncCall && $cond->expr->name == 'defined' && $cond->expr->args[0]->value->value == 'IN_PHPBB')
        {
            if ($node->stmts[0] instanceof Exit_)
            {
                // Found IN_PHPBB
                $this->in_phpbb = true;
            }
            else
            {
                // Found IN_PHPBB, but it didn't exists?
                // We dont set $this->in_phpbb, so parseNode continue running on this node.
                // Also include a notice.
                $this->addMessage(Messages::NOTICE, "IN_PHPBB check should exit if it not defined.");
            }
            if (sizeof($node->stmts) > 1)
            {
                $this->addMessage(Messages::NOTICE, "There should be no other statements as exit in the IN_PHPBB check");
                unset($node->stmts[0]);
                $this->parseNode($node->stmts[0]);
            }
        }
    }

    /**
     * Validate a language file.
     * @param array $node
     */
    private function parseLangNodes(array $node)
    {
        $this->parseNode($node);
    }

    /**
     * Add a new Message to Messages.
     * The filename is automaticlly added.
     *
     * @param $type
     * @param $message
     */
    private function addMessage($type, $message)
    {
        Messages::addMessage($type, sprintf("%s in %s", $message, $this->file->getFilename()));
    }

    /**
     *
     * @return String
     */
    public function testName()
    {
        return "Validate disallowed php functions";
    }
} 