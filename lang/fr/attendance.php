<?php

return [

    'statuses' => [
        'present' => 'Présent',
        'present_admin' => 'Présent (administratif)',
        'absent' => 'Absent',
        'absent_justified' => 'Absent (justifié)',
        'unpaired' => 'Non apparié',
        'location_mismatch' => 'Lieu incohérent',
    ],

    'day_types' => [
        'teaching' => "Jour d'enseignement",
        'public_holiday' => 'Jour férié',
        'school_closure' => "Fermeture de l'établissement",
        'half_day' => 'Demi-journée',
        'classes_suspended' => 'Cours suspendus',
    ],

    'session_states' => [
        'paired' => 'Apparié',
        'unpaired' => 'Non apparié',
    ],

    'sources' => [
        'scan' => 'Pointage',
        'administrative' => 'Administratif',
        'override' => 'Dérogation',
    ],

    'roles' => [
        'admin' => 'Administrateur',
        'officer' => 'Agent',
        'principal' => 'Proviseur',
        'hr' => 'RH',
    ],

    'employment_types' => [
        'hourly' => 'Vacataire (payé à l\'heure)',
        'salaried' => 'Salarié',
    ],

];
