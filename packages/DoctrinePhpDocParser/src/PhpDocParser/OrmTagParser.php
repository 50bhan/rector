<?php declare(strict_types=1);

namespace Rector\DoctrinePhpDocParser\PhpDocParser;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Nette\Utils\Strings;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Property;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagValueNode;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use Rector\BetterPhpDocParser\PhpDocParser\AbstractPhpDocParser;
use Rector\DoctrinePhpDocParser\Ast\PhpDoc\Class_\EntityTagValueNode;
use Rector\DoctrinePhpDocParser\Ast\PhpDoc\Class_\InheritanceTypeTagValueNode;
use Rector\DoctrinePhpDocParser\Ast\PhpDoc\Class_\TableTagValueNode;
use Rector\DoctrinePhpDocParser\Ast\PhpDoc\Property_\ColumnTagValueNode;
use Rector\DoctrinePhpDocParser\Ast\PhpDoc\Property_\GeneratedValueTagValueNode;
use Rector\DoctrinePhpDocParser\Ast\PhpDoc\Property_\IdTagValueNode;
use Rector\DoctrinePhpDocParser\Ast\PhpDoc\Property_\JoinColumnTagValueNode;
use Rector\DoctrinePhpDocParser\Ast\PhpDoc\Property_\JoinTableTagValueNode;
use Rector\DoctrinePhpDocParser\Ast\PhpDoc\Property_\ManyToManyTagValueNode;
use Rector\DoctrinePhpDocParser\Ast\PhpDoc\Property_\ManyToOneTagValueNode;
use Rector\DoctrinePhpDocParser\Ast\PhpDoc\Property_\OneToManyTagValueNode;
use Rector\DoctrinePhpDocParser\Ast\PhpDoc\Property_\OneToOneTagValueNode;
use Rector\DoctrinePhpDocParser\Contract\Ast\PhpDoc\DoctrineTagNodeInterface;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\PhpParser\Node\Resolver\NameResolver;

/**
 * Parses various ORM annotations
 *
 * @see \Rector\DoctrinePhpDocParser\Tests\PhpDocParser\OrmTagParser\Class_\OrmTagParserClassTest
 * @see \Rector\DoctrinePhpDocParser\Tests\PhpDocParser\OrmTagParser\Property_\OrmTagParserPropertyTest
 */
final class OrmTagParser extends AbstractPhpDocParser
{
    /**
     * @var string
     */
    private const JOIN_COLUMN_PATTERN = '#@ORM\\\\JoinColumn\((?<singleJoinColumn>.*?)\),?#s';

    /**
     * @var NameResolver
     */
    private $nameResolver;

    /**
     * @var TableTagValueNodeFactory
     */
    private $tableTagValueNodeFactory;

    public function __construct(NameResolver $nameResolver, TableTagValueNodeFactory $tableTagValueNodeFactory)
    {
        $this->nameResolver = $nameResolver;
        $this->tableTagValueNodeFactory = $tableTagValueNodeFactory;
    }

    public function parse(TokenIterator $tokenIterator, string $tag): ?PhpDocTagValueNode
    {
        /** @var Class_|Property $currentPhpNode */
        $currentPhpNode = $this->getCurrentPhpNode();

        $annotationContent = $this->resolveAnnotationContent($tokenIterator);

        // Entity tags
        if ($currentPhpNode instanceof Class_) {
            if ($tag === EntityTagValueNode::SHORT_NAME) {
                return $this->createEntityTagValueNode($currentPhpNode, $annotationContent);
            }

            if ($tag === InheritanceTypeTagValueNode::SHORT_NAME) {
                return $this->createInheritanceTypeTagValueNode($currentPhpNode);
            }

            if ($tag === TableTagValueNode::SHORT_NAME) {
                return $this->tableTagValueNodeFactory->create($currentPhpNode, $annotationContent);
            }
        }

        // Property tags
        if ($currentPhpNode instanceof Property) {
            if ($this->shouldSkipProperty($currentPhpNode)) {
                return null;
            }

            return $this->createPropertyTagValueNode($tag, $currentPhpNode, $annotationContent);
        }

        return null;
    }

    /**
     * @return ColumnTagValueNode|JoinColumnTagValueNode|OneToManyTagValueNode|ManyToManyTagValueNode|OneToOneTagValueNode|ManyToOneTagValueNode|JoinTableTagValueNode
     */
    private function createPropertyTagValueNode(
        string $tag,
        Property $property,
        string $annotationContent
    ): ?DoctrineTagNodeInterface {
        if ($tag === IdTagValueNode::SHORT_NAME) {
            return $this->createIdTagValueNode();
        }

        if ($tag === ColumnTagValueNode::SHORT_NAME) {
            return $this->createColumnTagValueNode($property, $annotationContent);
        }

        if ($tag === GeneratedValueTagValueNode::SHORT_NAME) {
            return $this->createGeneratedValueTagValueNode($property, $annotationContent);
        }

        if ($tag === JoinColumnTagValueNode::SHORT_NAME) {
            return $this->createJoinColumnTagValueNode($property, $annotationContent);
        }

        if ($tag === ManyToManyTagValueNode::SHORT_NAME) {
            return $this->createManyToManyTagValueNode($property, $annotationContent);
        }

        if ($tag === ManyToOneTagValueNode::SHORT_NAME) {
            return $this->createManyToOneTagValueNode($property, $annotationContent);
        }

        if ($tag === OneToOneTagValueNode::SHORT_NAME) {
            return $this->createOneToOneTagValueNode($property, $annotationContent);
        }

        if ($tag === OneToManyTagValueNode::SHORT_NAME) {
            return $this->createOneToManyTagValueNode($property, $annotationContent);
        }

        if ($tag === JoinTableTagValueNode::SHORT_NAME) {
            return $this->createJoinTableTagValeNode($property, $annotationContent);
        }

        return null;
    }

    private function createIdTagValueNode(): IdTagValueNode
    {
        return new IdTagValueNode();
    }

    private function createEntityTagValueNode(Class_ $class, string $annotationContent): EntityTagValueNode
    {
        /** @var Entity $entity */
        $entity = $this->nodeAnnotationReader->readClassAnnotation($class, Entity::class);

        return new EntityTagValueNode($entity->repositoryClass, $entity->readOnly, $annotationContent);
    }

    private function createInheritanceTypeTagValueNode(Class_ $class): InheritanceTypeTagValueNode
    {
        /** @var InheritanceType $inheritanceType */
        $inheritanceType = $this->nodeAnnotationReader->readClassAnnotation($class, InheritanceType::class);

        return new InheritanceTypeTagValueNode($inheritanceType->value);
    }

    private function createColumnTagValueNode(Property $property, string $annotationContent): ColumnTagValueNode
    {
        /** @var Column $column */
        $column = $this->nodeAnnotationReader->readPropertyAnnotation($property, Column::class);

        return new ColumnTagValueNode(
            $column->name,
            $column->type,
            $column->length,
            $column->precision,
            $column->scale,
            $column->unique,
            $column->nullable,
            $column->options,
            $column->columnDefinition,
            $annotationContent
        );
    }

    private function createManyToManyTagValueNode(Property $property, string $annotationContent): ManyToManyTagValueNode
    {
        /** @var ManyToMany $manyToMany */
        $manyToMany = $this->nodeAnnotationReader->readPropertyAnnotation($property, ManyToMany::class);

        return new ManyToManyTagValueNode(
            $manyToMany->targetEntity,
            $manyToMany->mappedBy,
            $manyToMany->inversedBy,
            $manyToMany->cascade,
            $manyToMany->fetch,
            $manyToMany->orphanRemoval,
            $manyToMany->indexBy,
            $annotationContent,
            $this->resolveFqnTargetEntity($manyToMany->targetEntity, $property)
        );
    }

    private function createManyToOneTagValueNode(Property $property, string $annotationContent): ManyToOneTagValueNode
    {
        /** @var ManyToOne $manyToOne */
        $manyToOne = $this->nodeAnnotationReader->readPropertyAnnotation($property, ManyToOne::class);

        return new ManyToOneTagValueNode(
            $manyToOne->targetEntity,
            $manyToOne->cascade,
            $manyToOne->fetch,
            $manyToOne->inversedBy,
            $annotationContent,
            $this->resolveFqnTargetEntity($manyToOne->targetEntity, $property)
        );
    }

    private function createOneToOneTagValueNode(Property $property, string $annotationContent): OneToOneTagValueNode
    {
        /** @var OneToOne $oneToOne */
        $oneToOne = $this->nodeAnnotationReader->readPropertyAnnotation($property, OneToOne::class);

        return new OneToOneTagValueNode(
            $oneToOne->targetEntity,
            $oneToOne->mappedBy,
            $oneToOne->inversedBy,
            $oneToOne->cascade,
            $oneToOne->fetch,
            $oneToOne->orphanRemoval,
            $annotationContent,
            $this->resolveFqnTargetEntity($oneToOne->targetEntity, $property)
        );
    }

    private function createOneToManyTagValueNode(Property $property, string $annotationContent): OneToManyTagValueNode
    {
        /** @var OneToMany $oneToMany */
        $oneToMany = $this->nodeAnnotationReader->readPropertyAnnotation($property, OneToMany::class);

        return new OneToManyTagValueNode(
            $oneToMany->mappedBy,
            $oneToMany->targetEntity,
            $oneToMany->cascade,
            $oneToMany->fetch,
            $oneToMany->orphanRemoval,
            $oneToMany->indexBy,
            $annotationContent,
            $this->resolveFqnTargetEntity($oneToMany->targetEntity, $property)
        );
    }

    private function createJoinColumnTagValueNode(Property $property, string $annotationContent): JoinColumnTagValueNode
    {
        /** @var JoinColumn $joinColumn */
        $joinColumn = $this->nodeAnnotationReader->readPropertyAnnotation($property, JoinColumn::class);

        return $this->createJoinColumnTagValueNodeFromJoinColumnAnnotation($joinColumn, $annotationContent);
    }

    private function createGeneratedValueTagValueNode(
        Property $property,
        string $annotationContent
    ): GeneratedValueTagValueNode {
        /** @var GeneratedValue $generatedValue */
        $generatedValue = $this->nodeAnnotationReader->readPropertyAnnotation($property, GeneratedValue::class);

        return new GeneratedValueTagValueNode($generatedValue->strategy);
    }

    private function createJoinTableTagValeNode(Property $property, string $annotationContent): JoinTableTagValueNode
    {
        /** @var JoinTable $joinTable */
        $joinTable = $this->nodeAnnotationReader->readPropertyAnnotation($property, JoinTable::class);

        $joinColumnValuesTags = $this->createJoinColumnTagValues($annotationContent, $joinTable, 'joinColumns');
        $inverseJoinColumnValuesTags = $this->createJoinColumnTagValues(
            $annotationContent,
            $joinTable,
            'inverseJoinColumns'
        );

        return new JoinTableTagValueNode(
            $joinTable->name,
            $joinTable->schema,
            $joinColumnValuesTags,
            $inverseJoinColumnValuesTags,
            $annotationContent
        );
    }

    private function resolveFqnTargetEntity(string $targetEntity, Node $node): string
    {
        if (class_exists($targetEntity)) {
            return $targetEntity;
        }

        $namespacedTargetEntity = $node->getAttribute(AttributeKey::NAMESPACE_NAME) . '\\' . $targetEntity;
        if (class_exists($namespacedTargetEntity)) {
            return $namespacedTargetEntity;
        }

        // probably tested class
        return $targetEntity;
    }

    private function createJoinColumnTagValueNodeFromJoinColumnAnnotation(
        JoinColumn $joinColumn,
        string $annotationContent
    ): JoinColumnTagValueNode {
        return new JoinColumnTagValueNode(
            $joinColumn->name,
            $joinColumn->referencedColumnName,
            $joinColumn->unique,
            $joinColumn->nullable,
            $joinColumn->onDelete,
            $joinColumn->columnDefinition,
            $joinColumn->fieldName,
            $annotationContent
        );
    }

    private function shouldSkipProperty(Property $property): bool
    {
        // required attribute for further reflection, probably new node → skip
        if ($property->getAttribute(AttributeKey::CLASS_NAME) === null) {
            return true;
        }

        // be sure the property exists → if not, this node was probably just added
        $className = $property->getAttribute(AttributeKey::CLASS_NAME);
        $propertyName = $this->nameResolver->getName($property);

        return ! property_exists($className, $propertyName);
    }

    /**
     * @return string[][]
     */
    private function matchJoinColumnContents(string $annotationContent, string $type): array
    {
        $match = Strings::match($annotationContent, '#' . $type . '=\{(?<content>.*?)\}#sm');
        if (! isset($match['content'])) {
            return [];
        }

        return Strings::matchAll($match['content'], self::JOIN_COLUMN_PATTERN);
    }

    /**
     * @return JoinColumnTagValueNode[]
     */
    private function createJoinColumnTagValues(string $annotationContent, JoinTable $joinTable, string $type): array
    {
        $joinColumnContents = $this->matchJoinColumnContents($annotationContent, $type);
        $joinColumnValuesTags = [];

        foreach ($joinTable->joinColumns as $key => $joinColumn) {
            $currentJoinColumnContent = $joinColumnContents[$key]['singleJoinColumn'];
            $joinColumnValuesTags[] = $this->createJoinColumnTagValueNodeFromJoinColumnAnnotation(
                $joinColumn,
                $currentJoinColumnContent
            );
        }

        return $joinColumnValuesTags;
    }
}