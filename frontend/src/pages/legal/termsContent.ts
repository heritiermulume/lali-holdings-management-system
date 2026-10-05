import type { LegalDocument } from './types'

export const termsContent: Record<'fr' | 'en', LegalDocument> = {
  fr: {
    title: 'Conditions générales d’utilisation',
    updatedAt: '5 octobre 2026',
    intro:
      'Les présentes Conditions générales d’utilisation (ci-après les « CGU ») régissent l’accès et l’utilisation de la plateforme LALI Holdings Management System (ci-après « LHMS »), éditée et opérée pour le compte de Lali Holdings Group (ci-après le « Groupe »). En accédant à LHMS ou en utilisant ses services, vous acceptez sans réserve les présentes CGU.',
    contactNote:
      'Pour toute question relative aux présentes CGU, contactez l’administration du Groupe via les canaux internes prévus (support LHMS ou direction des systèmes d’information).',
    sections: [
      {
        title: '1. Objet',
        paragraphs: [
          'Les CGU ont pour objet de définir les conditions dans lesquelles les utilisateurs autorisés peuvent accéder à LHMS, l’utiliser et y traiter des données dans le cadre des activités du Groupe et de ses sociétés affiliées.',
          'LHMS est un système de gestion multi-pays, multi-sociétés et multi-modules destiné exclusivement à un usage professionnel interne et aux partenaires dûment habilités.',
        ],
      },
      {
        title: '2. Définitions',
        paragraphs: ['Aux fins des présentes CGU, les termes suivants ont la signification indiquée ci-dessous :'],
        bullets: [
          '« Utilisateur » : toute personne physique disposant d’un compte LHMS actif.',
          '« Compte » : identifiants et droits d’accès attribués à un Utilisateur.',
          '« Contenu » : données, documents, fichiers, paramètres et informations saisis, importés ou générés dans LHMS.',
          '« Modules » : composants fonctionnels de LHMS (organisation, finance, recrutement, stock, etc.).',
          '« Administrateur » : Utilisateur habilité à gérer les accès, rôles, sociétés, modules ou paramètres.',
        ],
      },
      {
        title: '3. Accès au service',
        paragraphs: [
          'L’accès à LHMS est réservé aux personnes expressément autorisées par le Groupe ou une société affiliée. La création d’un compte, l’attribution de rôles et de permissions, ainsi que la désactivation d’accès relèvent exclusivement des Administrateurs habilités.',
          'L’Utilisateur s’engage à fournir des informations exactes, à maintenir la confidentialité de ses identifiants et à informer sans délai l’Administrateur en cas de suspicion d’accès non autorisé.',
          'Le Groupe peut suspendre, limiter ou révoquer l’accès à LHMS, immédiatement et sans préavis, en cas de non-respect des CGU, de risque de sécurité, de départ de l’Utilisateur, ou pour toute raison légitime liée à la protection des systèmes et des données.',
        ],
      },
      {
        title: '4. Création et gestion du compte',
        paragraphs: [
          'Chaque Compte est personnel et non cessible. L’Utilisateur ne doit pas partager ses identifiants, ni permettre à un tiers d’utiliser son Compte.',
          'L’Utilisateur est responsable de toutes les actions réalisées via son Compte, sauf preuve d’une compromission dont il n’est pas à l’origine et qu’il a signalée diligemment.',
          'Les Administrateurs peuvent imposer des exigences de sécurité (complexité du mot de passe, renouvellement, authentification, restriction d’adresse, etc.).',
        ],
      },
      {
        title: '5. Utilisation autorisée',
        paragraphs: ['L’Utilisateur s’engage à utiliser LHMS de manière loyale, conforme à la loi et aux politiques internes du Groupe. Il est notamment interdit de :'],
        bullets: [
          'tenter d’accéder à des données, modules, sociétés ou fonctionnalités non autorisés ;',
          'contourner, désactiver ou tester de manière abusive des mesures de sécurité ;',
          'introduire des logiciels malveillants, scripts non autorisés ou charges susceptibles de dégrader le service ;',
          'extraire, copier, exporter ou divulguer des données hors des finalités professionnelles autorisées ;',
          'falsifier des informations, altérer des journaux ou compromettre l’intégrité des données ;',
          'utiliser LHMS à des fins personnelles, commerciales externes, concurrentielles ou illicites.',
        ],
      },
      {
        title: '6. Données et contenu',
        paragraphs: [
          'Le Contenu saisi dans LHMS demeure la propriété du Groupe ou de la société concernée, sous réserve des droits des tiers. L’Utilisateur garantit disposer des droits nécessaires pour saisir, importer ou traiter le Contenu qu’il fournit.',
          'L’Utilisateur s’engage à ne saisir que des informations pertinentes, exactes et proportionnées aux besoins métier. Les données à caractère personnel doivent être traitées conformément à la Politique de confidentialité et aux instructions internes.',
          'Le Groupe peut conserver, archiver, anonymiser ou supprimer des données selon ses obligations légales, ses besoins opérationnels et ses politiques de rétention.',
        ],
      },
      {
        title: '7. Rôles, permissions et périmètre organisationnel',
        paragraphs: [
          'LHMS repose sur un modèle de permissions granulaires et de périmètres (pays, sociétés, bureaux, modules). L’Utilisateur ne doit utiliser que les droits qui lui ont été attribués et ne doit pas solliciter ni exploiter des élévations de privilèges non autorisées.',
          'Les Administrateurs sont responsables de l’adéquation des droits accordés au principe du moindre privilège.',
        ],
      },
      {
        title: '8. Disponibilité et maintenance',
        paragraphs: [
          'Le Groupe s’efforce d’assurer une disponibilité raisonnable de LHMS. Des interruptions peuvent toutefois survenir pour maintenance, mises à jour, incidents, force majeure ou dépendances techniques.',
          'Aucune garantie absolue de disponibilité ininterrompue, d’absence d’erreur ou d’adéquation à un besoin particulier n’est fournie, sauf engagement écrit spécifique.',
        ],
      },
      {
        title: '9. Sécurité',
        paragraphs: [
          'Le Groupe met en œuvre des mesures techniques et organisationnelles destinées à protéger LHMS. L’Utilisateur contribue à cette sécurité en appliquant les bonnes pratiques (mots de passe robustes, déconnexion sur postes partagés, signalement d’anomalies, etc.).',
          'Toute tentative d’intrusion, de scan non autorisé, d’ingénierie sociale ou d’exploitation de vulnérabilité est strictement interdite et peut entraîner des sanctions disciplinaires, civiles et/ou pénales.',
        ],
      },
      {
        title: '10. Propriété intellectuelle',
        paragraphs: [
          'LHMS, son architecture, ses interfaces, logos, marques, bases de données, documentations et composants logiciels sont protégés par les droits de propriété intellectuelle applicables.',
          'Aucune licence n’est concédée à l’Utilisateur au-delà du droit d’usage limité, personnel, non exclusif et révocable nécessaire à l’exercice de ses fonctions. Toute reproduction, adaptation, rétro-ingénierie ou redistribution non autorisée est interdite.',
        ],
      },
      {
        title: '11. Responsabilité',
        paragraphs: [
          'Dans les limites autorisées par la loi, le Groupe ne saurait être tenu responsable des dommages indirects, pertes de chance, pertes de données résultant d’un usage non conforme, d’une mauvaise configuration par l’Utilisateur, d’un cas de force majeure, ou d’une interruption temporaire du service.',
          'L’Utilisateur demeure responsable des décisions métier prises à partir des informations présentes dans LHMS et de la vérification de leur exactitude dans son contexte opérationnel.',
        ],
      },
      {
        title: '12. Suspension et résiliation',
        paragraphs: [
          'En cas de manquement aux CGU, le Groupe peut suspendre ou résilier l’accès de l’Utilisateur sans indemnité. La fin de la relation contractuelle ou du mandat de l’Utilisateur entraîne la désactivation de son Compte selon les procédures internes.',
          'Les clauses qui, par nature, doivent survivre (propriété intellectuelle, responsabilité, confidentialité) demeurent applicables après la fin de l’accès.',
        ],
      },
      {
        title: '13. Modifications des CGU',
        paragraphs: [
          'Le Groupe peut modifier les présentes CGU à tout moment. La version applicable est celle publiée dans LHMS à la date d’utilisation. En cas de modification substantielle, une information pourra être communiquée aux Utilisateurs par les moyens appropriés.',
          'La poursuite de l’utilisation de LHMS après publication d’une nouvelle version vaut acceptation des CGU mises à jour.',
        ],
      },
      {
        title: '14. Droit applicable et litiges',
        paragraphs: [
          'Les présentes CGU sont régies par le droit applicable au siège du Groupe, sous réserve des dispositions impératives locales pouvant s’appliquer selon le pays d’établissement de la société utilisatrice.',
          'En cas de différend, les parties privilégient une résolution amiable. À défaut, les juridictions compétentes seront celles prévues par le droit applicable.',
        ],
      },
    ],
  },
  en: {
    title: 'Terms of use',
    updatedAt: '5 October 2026',
    intro:
      'These Terms of Use (the “Terms”) govern access to and use of the LALI Holdings Management System platform (“LHMS”), operated for Lali Holdings Group (the “Group”). By accessing or using LHMS, you accept these Terms in full.',
    contactNote:
      'For any question regarding these Terms, contact Group administration through the designated internal channels (LHMS support or IT leadership).',
    sections: [
      {
        title: '1. Purpose',
        paragraphs: [
          'These Terms define the conditions under which authorized users may access LHMS, use it, and process data in connection with the activities of the Group and its affiliates.',
          'LHMS is a multi-country, multi-company, multi-module management system intended solely for internal professional use and duly authorized partners.',
        ],
      },
      {
        title: '2. Definitions',
        paragraphs: ['For the purposes of these Terms:'],
        bullets: [
          '“User”: any natural person with an active LHMS account.',
          '“Account”: credentials and access rights assigned to a User.',
          '“Content”: data, documents, files, settings, and information entered, imported, or generated in LHMS.',
          '“Modules”: functional components of LHMS (organization, finance, recruitment, stock, etc.).',
          '“Administrator”: a User authorized to manage access, roles, companies, modules, or settings.',
        ],
      },
      {
        title: '3. Access to the service',
        paragraphs: [
          'Access to LHMS is restricted to persons expressly authorized by the Group or an affiliate. Account creation, role and permission assignment, and access revocation are handled exclusively by authorized Administrators.',
          'Users must provide accurate information, keep credentials confidential, and promptly notify an Administrator of any suspected unauthorized access.',
          'The Group may suspend, restrict, or revoke access immediately and without notice in case of breach of these Terms, security risk, end of the User’s relationship, or any legitimate reason related to system and data protection.',
        ],
      },
      {
        title: '4. Account creation and management',
        paragraphs: [
          'Each Account is personal and non-transferable. Users must not share credentials or allow third parties to use their Account.',
          'Users are responsible for all actions performed through their Account, unless they can show a compromise they did not cause and reported promptly.',
          'Administrators may impose security requirements (password complexity, renewal, authentication, address restrictions, etc.).',
        ],
      },
      {
        title: '5. Permitted use',
        paragraphs: ['Users must use LHMS lawfully and in accordance with Group policies. In particular, Users must not:'],
        bullets: [
          'attempt to access unauthorized data, modules, companies, or features;',
          'bypass, disable, or abusively test security controls;',
          'introduce malware, unauthorized scripts, or loads that may degrade the service;',
          'extract, copy, export, or disclose data outside authorized business purposes;',
          'falsify information, alter logs, or compromise data integrity;',
          'use LHMS for personal, external commercial, competitive, or unlawful purposes.',
        ],
      },
      {
        title: '6. Data and content',
        paragraphs: [
          'Content entered in LHMS remains the property of the Group or the relevant company, subject to third-party rights. Users warrant they have the rights needed to enter, import, or process the Content they provide.',
          'Users must enter only relevant, accurate, and proportionate information. Personal data must be processed in accordance with the Privacy Policy and internal instructions.',
          'The Group may retain, archive, anonymize, or delete data according to legal obligations, operational needs, and retention policies.',
        ],
      },
      {
        title: '7. Roles, permissions, and organizational scope',
        paragraphs: [
          'LHMS relies on granular permissions and scopes (countries, companies, offices, modules). Users may only use the rights assigned to them and must not seek or exploit unauthorized privilege elevation.',
          'Administrators are responsible for aligning granted rights with the principle of least privilege.',
        ],
      },
      {
        title: '8. Availability and maintenance',
        paragraphs: [
          'The Group strives to provide reasonable availability of LHMS. Interruptions may occur for maintenance, updates, incidents, force majeure, or technical dependencies.',
          'No absolute warranty of uninterrupted availability, error-free operation, or fitness for a particular purpose is provided, unless a specific written commitment applies.',
        ],
      },
      {
        title: '9. Security',
        paragraphs: [
          'The Group implements technical and organizational measures to protect LHMS. Users contribute by following good practices (strong passwords, signing out on shared devices, reporting anomalies, etc.).',
          'Any unauthorized intrusion attempt, scanning, social engineering, or vulnerability exploitation is strictly prohibited and may lead to disciplinary, civil, and/or criminal sanctions.',
        ],
      },
      {
        title: '10. Intellectual property',
        paragraphs: [
          'LHMS, its architecture, interfaces, logos, trademarks, databases, documentation, and software components are protected by applicable intellectual property rights.',
          'No license is granted beyond a limited, personal, non-exclusive, revocable right of use necessary to perform the User’s duties. Unauthorized reproduction, adaptation, reverse engineering, or redistribution is prohibited.',
        ],
      },
      {
        title: '11. Liability',
        paragraphs: [
          'To the extent permitted by law, the Group is not liable for indirect damages, loss of opportunity, or data loss arising from non-compliant use, User misconfiguration, force majeure, or temporary service interruption.',
          'Users remain responsible for business decisions made using information in LHMS and for verifying its accuracy in their operational context.',
        ],
      },
      {
        title: '12. Suspension and termination',
        paragraphs: [
          'In case of breach of these Terms, the Group may suspend or terminate User access without compensation. The end of the User’s engagement results in Account deactivation under internal procedures.',
          'Provisions that by nature should survive (intellectual property, liability, confidentiality) remain applicable after access ends.',
        ],
      },
      {
        title: '13. Changes to the Terms',
        paragraphs: [
          'The Group may amend these Terms at any time. The applicable version is the one published in LHMS on the date of use. Material changes may be communicated to Users by appropriate means.',
          'Continued use of LHMS after publication of a new version constitutes acceptance of the updated Terms.',
        ],
      },
      {
        title: '14. Governing law and disputes',
        paragraphs: [
          'These Terms are governed by the law applicable at the Group’s registered office, subject to mandatory local provisions that may apply depending on the user’s company country.',
          'In case of dispute, the parties will first seek an amicable resolution. Failing that, the competent courts under applicable law shall have jurisdiction.',
        ],
      },
    ],
  },
}
