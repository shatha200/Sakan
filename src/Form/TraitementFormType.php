<?php

namespace App\Form;

use App\Entity\Traitement;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Length;

class TraitementFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('reponse', TextareaType::class, [
                'label' => 'Réponse :',
                'attr' => [
                    'placeholder' => 'Saisissez votre réponse ici...',
                    'rows' => 6,
                    'class' => 'form-control input-custom'
                ],
                'constraints' => [
                    new NotBlank(['message' => 'Veuillez saisir une réponse.']),
                    new Length([
                        'min' => 10,
                        'minMessage' => 'La réponse doit faire au moins 10 caractères.',
                    ]),
                ],
            ])
            ->add('dateTraitement', \Symfony\Component\Form\Extension\Core\Type\DateType::class, [
                'label' => 'Date traitement :',
                'widget' => 'single_text',
                'attr' => [
                    'class' => 'form-control input-custom'
                ],
                'data' => new \DateTime(),
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Traitement::class,
        ]);
    }
}
