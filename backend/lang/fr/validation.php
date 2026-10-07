<?php

return [
    'required' => 'Le champ :attribute est obligatoire.',
    'string' => 'Le champ :attribute doit être une chaîne de caractères.',
    'numeric' => 'Le champ :attribute doit être un nombre.',
    'integer' => 'Le champ :attribute doit être un entier.',
    'exists' => 'Le champ :attribute sélectionné est invalide.',
    'confirmed' => 'La confirmation du champ :attribute ne correspond pas.',
    'current_password' => 'Le mot de passe est incorrect.',
    'min' => [
        'numeric' => 'Le champ :attribute doit être au moins :min.',
        'string' => 'Le champ :attribute doit contenir au moins :min caractères.',
    ],
    'max' => [
        'numeric' => 'Le champ :attribute ne doit pas dépasser :max.',
        'string' => 'Le champ :attribute ne doit pas dépasser :max caractères.',
    ],
    'password' => [
        'letters' => 'Le champ :attribute doit contenir au moins une lettre.',
        'mixed' => 'Le champ :attribute doit contenir au moins une majuscule et une minuscule.',
        'numbers' => 'Le champ :attribute doit contenir au moins un chiffre.',
        'symbols' => 'Le champ :attribute doit contenir au moins un symbole.',
        'uncompromised' => 'Le :attribute choisi est trop exposé. Veuillez en choisir un autre.',
    ],

    'unique' => 'Le champ :attribute est déjà utilisé.',

    'attributes' => [
        'notes' => 'motif',
        'amount' => 'montant',
        'member_id' => 'agent',
        'product_id' => 'produit',
        'quantity' => 'quantité',
        'items' => 'lignes',
        'items.*.product_id' => 'produit',
        'items.*.quantity' => 'quantité',
        'items.*.quantity_returned' => 'quantité restituée',
        'items.*.quantity_lost' => 'quantité perdue',
        'items.*.unit_price' => 'prix unitaire',
        'items.*.unit_cost' => 'coût unitaire',
        'collected_amount' => 'montant collecté',
        'system_module_id' => 'module',
        'code' => 'code',
        'country_id' => 'pays',
        'current_password' => 'mot de passe actuel',
        'password' => 'mot de passe',
        'password_confirmation' => 'confirmation du mot de passe',
    ],
];
