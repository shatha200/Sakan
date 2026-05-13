<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\BooleanType;
use Doctrine\DBAL\Types\DateImmutableType;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\DBAL\Types\DateTimeType;
use Doctrine\DBAL\Types\DateType;
use Doctrine\DBAL\Types\DecimalType;
use Doctrine\DBAL\Types\FloatType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\TextType;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:generate-entities', description: 'Generate entities from existing database')]
class GenerateEntitiesCommand extends Command
{
    public function __construct(private Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $schemaManager = $this->connection->createSchemaManager();
        $tables = $schemaManager->listTables();

        $entityDir = __DIR__ . '/../Entity/';
        if (!is_dir($entityDir)) {
            mkdir($entityDir, 0777, true);
        }

        foreach ($tables as $table) {
            $tableName  = $table->getName();
            $className  = $this->toClassName($tableName);
            $columns    = $table->getColumns();
            $pkColumns  = $table->getPrimaryKey()?->getColumns() ?? ['id'];

            $code = $this->generateEntity($className, $tableName, $columns, $pkColumns);
            file_put_contents($entityDir . $className . '.php', $code);
            $output->writeln("<info>✔ Généré :</info> $className  ← table `$tableName`");
        }

        $output->writeln("\n<comment>Lancez maintenant :</comment> php bin/console make:entity --regenerate App");
        return Command::SUCCESS;
    }

    private function toClassName(string $table): string
    {
        return str_replace('_', '', ucwords($table, '_'));
    }

    private function toPropertyName(string $column): string
    {
        $parts = explode('_', $column);
        $first = array_shift($parts);
        return $first . implode('', array_map('ucfirst', $parts));
    }

    private function doctrineType(\Doctrine\DBAL\Types\Type $type): string
    {
        return match (true) {
            $type instanceof IntegerType                => 'integer',
            $type instanceof StringType                 => 'string',
            $type instanceof TextType                   => 'text',
            $type instanceof BooleanType                => 'boolean',
            $type instanceof FloatType,
            $type instanceof DecimalType                => 'float',
            $type instanceof DateType                   => 'date',
            $type instanceof DateTimeType,
            $type instanceof DateTimeImmutableType      => 'datetime',
            default                                     => 'string',
        };
    }

    private function phpType(string $doctrineType): string
    {
        return match ($doctrineType) {
            'integer'           => 'int',
            'boolean'           => 'bool',
            'float'             => 'float',
            'date', 'datetime'  => '\DateTimeInterface',
            default             => 'string',
        };
    }

    /**
     * @param array<int, mixed> $columns
     * @param array<int, string> $pkColumns
     */
    private function generateEntity(string $class, string $table, array $columns, array $pkColumns): string
    {
        $properties = '';
        $methods    = '';

        foreach ($columns as $col) {
            $name     = $col->getName();
            $prop     = $this->toPropertyName($name);
            $isPk     = in_array($name, $pkColumns, true);
            $dType    = $this->doctrineType($col->getType());
            $phpType  = $this->phpType($dType);
            $nullable = !$col->getNotnull() ? '?' : '';
            $nullDef  = !$col->getNotnull() ? ' = null' : '';

            if ($isPk) {
                $properties .= <<<PHP

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int \$$prop = null;

PHP;
            } else {
                $properties .= <<<PHP

    #[ORM\Column(type: '$dType', nullable: true)]
    private {$nullable}{$phpType} \$$prop{$nullDef};

PHP;
            }

            $getter = 'get' . ucfirst($prop);
            $setter = 'set' . ucfirst($prop);

            $methods .= <<<PHP

    public function $getter(): {$nullable}{$phpType}
    {
        return \$this->$prop;
    }

    public function $setter({$nullable}{$phpType} \$$prop): static
    {
        \$this->$prop = \$$prop;
        return \$this;
    }

PHP;
        }

        return <<<PHP
<?php

namespace App\Entity;

use App\Repository\\{$class}Repository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: {$class}Repository::class)]
#[ORM\Table(name: '$table')]
class $class
{
$properties
$methods
}
PHP;
    }
}
