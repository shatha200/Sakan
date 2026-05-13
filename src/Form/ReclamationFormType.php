<?php

namespace App\Form;

use App\Entity\Reclamation;
use App\Entity\ReclamationType as EntityReclamationType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

class ReclamationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $contrats = $options['contrats'] ?? [];
        $choices = [];
        foreach ($contrats as $c) {
            $annonce = $c->getAnnonce();
            $label = $annonce ? $annonce->getTitre() . ' (' . $annonce->getAdresse() . ')' : 'Contrat #' . $c->getId();
            $choices[$label] = $c->getId();
        }

        $builder
            ->add('contrat', EntityType::class, [
                'class' => \App\Entity\Contrat::class,
                'choices' => $contrats,
                'choice_label' => function(\App\Entity\Contrat $c) {
                    $annonce = $c->getAnnonce();
                    return $annonce ? $annonce->getTitre() . ' (' . ($annonce->getAdresse() ?? '') . ')' : 'Contrat #' . $c->getId();
                },
                'placeholder' => 'Sélectionnez le bien concerné',
                'label' => 'Bien concerné',
                'attr' => ['class' => 'form-select select-custom'],
                'required' => true,
                'constraints' => [
                    new NotBlank(['message' => 'Veuillez choisir un bien.']),
                ],
            ])
            ->add('typeId', EntityType::class, [
                'class' => EntityReclamationType::class,
                'query_builder' => function (EntityRepository $er) {
                    return $er->createQueryBuilder('t')
                        ->where('t.id IN (SELECT MIN(t2.id) FROM App\Entity\ReclamationType t2 GROUP BY t2.libelle)')
                        ->orderBy('t.libelle', 'ASC');
                },
                'choice_label' => 'libelle',
                'placeholder' => 'Choisir un type',
                'label' => 'Type',
                'attr' => ['class' => 'form-select select-custom'],
                'required' => true,
                'constraints' => [
                    new NotBlank(['message' => 'Veuillez choisir un type.']),
                ],
            ])
            ->add('isAutre', CheckboxType::class, [
                'label' => 'Autre (ajouter un second type)',
                'mapped' => false,
                'required' => false,
                'attr' => ['class' => 'form-check-input check-custom'],
            ])
            ->add('typeAutre', TextType::class, [
                'label' => 'Précisez l\'autre type...',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Précisez l\'autre type...',
                    'class' => 'form-control input-custom mt-2',
                ],
                'constraints' => [
                    new Length([
                        'min' => 3,
                        'max' => 100,
                        'minMessage' => 'Le type doit faire au moins {{ limit }} caractères.',
                        'maxMessage' => 'Le type ne peut pas dépasser {{ limit }} caractères.'
                    ]),
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'attr' => [
                    'placeholder' => 'Décrivez votre réclamation...',
                    'rows' => 5,
                    'class' => 'form-control input-custom'
                ],
                'constraints' => [
                    new NotBlank(['message' => 'La description est obligatoire.']),
                    new Length([
                        'min' => 10,
                        'minMessage' => 'Au moins 10 caractères.',
                    ]),
                ],
            ])
            ->add('images', FileType::class, [
                'label' => 'Images (optionnel)',
                'mapped' => false,
                'required' => false,
                'multiple' => true,
                'attr' => ['class' => 'form-control file-custom'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Reclamation::class,
            'constraints' => [
                new Callback([$this, 'validateAutreType']),
            ],
            'contrats' => [],
        ]);
    }

    public function validateAutreType(mixed $data, ExecutionContextInterface $context): void
    {
        $form = $context->getRoot();
        $isAutre = $form->get('isAutre')->getData();
        $typeAutre = $form->get('typeAutre')->getData();

        if ($isAutre && empty(trim($typeAutre))) {
            $context->buildViolation('Précisez l\'autre type.')
                ->atPath('typeAutre')
                ->addViolation();
        }
    }
}
