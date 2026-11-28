<?php

declare(strict_types=1);

namespace App\Enum\Identity;

/** A gesture of the administration and placement screens, written to the administration journal (design-ux.md § 3.6). */
enum AdministrationAction: string
{
    case TeacherAuthorized = 'teacher_authorized';
    case TeacherAuthorizationRevoked = 'teacher_authorization_revoked';
    case AdministratorDesignated = 'administrator_designated';
    case AdministratorRevoked = 'administrator_revoked';
    case SchoolYearCreated = 'school_year_created';
    case SchoolYearMadeCurrent = 'school_year_made_current';
    case ClassCreated = 'class_created';
    case ClassUpdated = 'class_updated';
    case ClassDeleted = 'class_deleted';
    case ClassTeacherAdded = 'class_teacher_added';
    case ClassTeacherRemoved = 'class_teacher_removed';
    case DirectoryMappingSaved = 'directory_mapping_saved';
    case StudentPlaced = 'student_placed';
    case StudentMoved = 'student_moved';
    case StudentRemoved = 'student_removed';
    case StudentPromoted = 'student_promoted';
    case StudentDeparted = 'student_departed';
    case StudentReturned = 'student_returned';
    case StudentAssistanceChanged = 'student_assistance_changed';

    public function label(): string
    {
        return match ($this) {
            self::TeacherAuthorized => 'Enseignant ajouté à la filière',
            self::TeacherAuthorizationRevoked => 'Enseignant retiré de la filière',
            self::AdministratorDesignated => 'Administrateur désigné',
            self::AdministratorRevoked => 'Administrateur retiré',
            self::SchoolYearCreated => 'Année scolaire créée',
            self::SchoolYearMadeCurrent => 'Année rendue courante',
            self::ClassCreated => 'Classe créée',
            self::ClassUpdated => 'Classe modifiée',
            self::ClassDeleted => 'Classe supprimée',
            self::ClassTeacherAdded => 'Enseignant ajouté à une classe',
            self::ClassTeacherRemoved => 'Enseignant retiré d’une classe',
            self::DirectoryMappingSaved => 'Correspondance de l’annuaire enregistrée',
            self::StudentPlaced => 'Étudiant placé',
            self::StudentMoved => 'Étudiant changé de classe',
            self::StudentRemoved => 'Étudiant retiré d’une classe',
            self::StudentPromoted => 'Passage de classe',
            self::StudentDeparted => 'Étudiant sorti de la filière',
            self::StudentReturned => 'Étudiant remis à placer',
            self::StudentAssistanceChanged => 'Niveau d’assistance propre changé',
        };
    }
}
