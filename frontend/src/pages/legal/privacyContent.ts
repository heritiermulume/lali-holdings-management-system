import type { LegalDocument } from './types'

export const privacyContent: Record<'fr' | 'en', LegalDocument> = {
  fr: {
    title: 'Politique de confidentialité',
    updatedAt: '5 octobre 2026',
    intro:
      'La présente Politique de confidentialité décrit la manière dont Lali Holdings Group (ci-après le « Groupe ») collecte, utilise, conserve et protège les données à caractère personnel dans le cadre de l’utilisation de la plateforme LALI Holdings Management System (ci-après « LHMS »). Elle s’applique aux Utilisateurs de LHMS et, le cas échéant, aux personnes dont les données sont traitées via la plateforme.',
    contactNote:
      'Pour exercer vos droits ou poser une question relative à la protection des données, contactez l’administration du Groupe ou le référent désigné via les canaux internes LHMS.',
    sections: [
      {
        title: '1. Responsable du traitement',
        paragraphs: [
          'Le responsable du traitement des données traitées dans LHMS est Lali Holdings Group, agissant directement ou via la société affiliée pour le compte de laquelle les données sont collectées.',
          'Selon le module et le contexte opérationnel, une société du Groupe peut agir en qualité de responsable conjoint ou de sous-traitant interne. Les instructions internes du Groupe précisent la répartition des responsabilités.',
        ],
      },
      {
        title: '2. Données collectées',
        paragraphs: ['Selon votre profil et votre usage de LHMS, les catégories de données suivantes peuvent être traitées :'],
        bullets: [
          'données d’identité et de contact : nom, prénom, e-mail, téléphone, genre, photo de profil ;',
          'données de compte et d’authentification : identifiants, historique de connexion, préférences de langue et fuseau horaire ;',
          'données organisationnelles : société, bureau, rôles, permissions, rattachements hiérarchiques ;',
          'données métier : informations saisies dans les modules (membres, recrutement, stock, finance, clients, etc.) ;',
          'données techniques : journaux applicatifs, adresse IP, type d’appareil/navigateur, horodatages, traces de sécurité ;',
          'données de communication : messages système, notifications, échanges liés au support.',
        ],
      },
      {
        title: '3. Finalités du traitement',
        paragraphs: ['Les données sont traitées pour les finalités suivantes :'],
        bullets: [
          'créer, gérer et sécuriser les comptes Utilisateurs ;',
          'permettre l’accès aux modules et aux périmètres organisationnels autorisés ;',
          'assurer le fonctionnement opérationnel des processus métier du Groupe ;',
          'produire des tableaux de bord, consolidations et rapports de pilotage ;',
          'garantir la sécurité, la traçabilité et la prévention des fraudes ou abus ;',
          'respecter les obligations légales, comptables, sociales et réglementaires ;',
          'améliorer la qualité, la performance et l’expérience d’utilisation de LHMS.',
        ],
      },
      {
        title: '4. Bases légales',
        paragraphs: [
          'Selon le contexte et le droit applicable, les traitements reposent notamment sur : l’exécution d’un contrat ou de mesures précontractuelles (relation de travail, mandat, prestation) ; l’intérêt légitime du Groupe (sécurité, pilotage, amélioration du service) ; le respect d’obligations légales ; et, lorsque requis, le consentement de la personne concernée.',
        ],
      },
      {
        title: '5. Destinataires des données',
        paragraphs: [
          'Les données sont accessibles uniquement aux personnes habilitées au sein du Groupe, selon leurs rôles et permissions. Elles peuvent également être communiquées, dans la stricte mesure nécessaire, à :',
        ],
        bullets: [
          'des sociétés affiliées du Groupe pour des besoins de consolidation ou d’administration ;',
          'des prestataires techniques agissant pour le compte du Groupe (hébergement, messagerie, maintenance), soumis à des obligations de confidentialité ;',
          'des autorités compétentes lorsque la loi l’exige.',
        ],
      },
      {
        title: '6. Transferts et localisation',
        paragraphs: [
          'LHMS pouvant être utilisé dans plusieurs pays, certaines données peuvent être accessibles depuis différents établissements du Groupe selon les droits attribués.',
          'Lorsque des transferts hors du pays d’origine sont nécessaires, le Groupe met en place les garanties appropriées au regard du droit applicable et de ses politiques internes de sécurité.',
        ],
      },
      {
        title: '7. Durées de conservation',
        paragraphs: [
          'Les données sont conservées pendant la durée nécessaire aux finalités poursuivies, puis archivées ou supprimées conformément aux obligations légales et aux politiques de rétention du Groupe.',
          'À titre indicatif : les comptes inactifs peuvent être désactivés puis purgés selon la procédure interne ; les journaux de sécurité sont conservés pour une durée limitée liée aux besoins d’audit ; les données métier suivent les délais légaux propres à chaque domaine (RH, finance, stock, etc.).',
        ],
      },
      {
        title: '8. Sécurité des données',
        paragraphs: [
          'Le Groupe met en œuvre des mesures techniques et organisationnelles destinées à protéger les données contre l’accès non autorisé, la perte, l’altération ou la divulgation, notamment : contrôle d’accès par rôles, authentification, chiffrement des communications, journalisation, sauvegardes et cloisonnement des périmètres.',
          'Aucun système n’étant exempt de risque, l’Utilisateur doit également adopter des comportements prudents et signaler tout incident suspect.',
        ],
      },
      {
        title: '9. Cookies et stockage local',
        paragraphs: [
          'LHMS utilise des mécanismes de stockage local nécessaires au fonctionnement du service, notamment pour conserver le jeton d’authentification, la langue d’affichage, certaines préférences et l’option « Se souvenir de moi ».',
          'Ces éléments ont une finalité technique et de confort d’usage. Ils ne sont pas destinés à de la publicité tierce. Vous pouvez supprimer les données locales via les paramètres de votre navigateur ; cela peut toutefois nécessiter une nouvelle connexion.',
        ],
      },
      {
        title: '10. Droits des personnes',
        paragraphs: [
          'Selon le droit applicable, vous pouvez disposer de droits d’accès, de rectification, d’effacement, de limitation, d’opposition, de portabilité, ainsi que du droit de définir des directives relatives au sort de vos données.',
          'Les demandes s’exercent via les canaux internes du Groupe. Certaines demandes peuvent être refusées ou limitées lorsque la loi, la sécurité du système ou les droits de tiers s’y opposent. Une pièce d’identité ou une vérification d’identité peut être demandée en cas de doute raisonnable.',
        ],
      },
      {
        title: '11. Données de tiers saisies dans LHMS',
        paragraphs: [
          'Certains modules permettent de traiter des données concernant des candidats, membres, clients, fournisseurs ou autres tiers. Les Utilisateurs qui saisissent ces données doivent s’assurer qu’elles sont collectées et utilisées conformément à la loi et aux instructions du Groupe.',
          'Les informations inexactes, excessives ou non autorisées ne doivent pas être enregistrées dans LHMS.',
        ],
      },
      {
        title: '12. Mineurs',
        paragraphs: [
          'LHMS n’est pas destiné à être utilisé par des mineurs. Si des données relatives à des mineurs doivent être traitées dans un contexte métier spécifique, cela ne peut se faire que sous cadre légal approprié et instructions internes explicites.',
        ],
      },
      {
        title: '13. Modifications de la politique',
        paragraphs: [
          'Cette Politique de confidentialité peut être mise à jour pour refléter des évolutions légales, techniques ou organisationnelles. La version en vigueur est celle publiée dans LHMS.',
          'En cas de changement substantiel, une information pourra être communiquée aux Utilisateurs par les moyens appropriés.',
        ],
      },
      {
        title: '14. Contact',
        paragraphs: [
          'Pour toute question relative à la présente politique ou à l’exercice de vos droits, adressez-vous à l’administration LHMS / direction des systèmes d’information du Groupe via les canaux internes prévus.',
        ],
      },
    ],
  },
  en: {
    title: 'Privacy policy',
    updatedAt: '5 October 2026',
    intro:
      'This Privacy Policy explains how Lali Holdings Group (the “Group”) collects, uses, stores, and protects personal data in connection with the LALI Holdings Management System platform (“LHMS”). It applies to LHMS Users and, where relevant, to individuals whose data are processed through the platform.',
    contactNote:
      'To exercise your rights or ask a data-protection question, contact Group administration or the designated contact through internal LHMS channels.',
    sections: [
      {
        title: '1. Data controller',
        paragraphs: [
          'The controller of personal data processed in LHMS is Lali Holdings Group, acting directly or through the affiliate for which the data are collected.',
          'Depending on the module and operational context, a Group company may act as joint controller or internal processor. Internal Group instructions define the allocation of responsibilities.',
        ],
      },
      {
        title: '2. Data collected',
        paragraphs: ['Depending on your profile and use of LHMS, the following categories of data may be processed:'],
        bullets: [
          'identity and contact data: first name, last name, email, phone, gender, profile photo;',
          'account and authentication data: credentials, login history, language and timezone preferences;',
          'organizational data: company, office, roles, permissions, reporting lines;',
          'business data: information entered in modules (members, recruitment, stock, finance, clients, etc.);',
          'technical data: application logs, IP address, device/browser type, timestamps, security traces;',
          'communication data: system messages, notifications, support-related exchanges.',
        ],
      },
      {
        title: '3. Purposes of processing',
        paragraphs: ['Data are processed for the following purposes:'],
        bullets: [
          'create, manage, and secure User accounts;',
          'enable access to authorized modules and organizational scopes;',
          'support the Group’s operational business processes;',
          'produce dashboards, consolidations, and management reports;',
          'ensure security, traceability, and prevention of fraud or abuse;',
          'comply with legal, accounting, social, and regulatory obligations;',
          'improve LHMS quality, performance, and user experience.',
        ],
      },
      {
        title: '4. Legal bases',
        paragraphs: [
          'Depending on context and applicable law, processing relies in particular on: performance of a contract or pre-contractual steps (employment, mandate, service); the Group’s legitimate interests (security, steering, service improvement); compliance with legal obligations; and, where required, the data subject’s consent.',
        ],
      },
      {
        title: '5. Recipients of data',
        paragraphs: [
          'Data are accessible only to authorized persons within the Group according to their roles and permissions. They may also be shared, strictly as needed, with:',
        ],
        bullets: [
          'Group affiliates for consolidation or administration needs;',
          'technical providers acting on behalf of the Group (hosting, email, maintenance), bound by confidentiality obligations;',
          'competent authorities when required by law.',
        ],
      },
      {
        title: '6. Transfers and location',
        paragraphs: [
          'As LHMS may be used across multiple countries, some data may be accessible from different Group establishments according to assigned rights.',
          'Where transfers outside the country of origin are necessary, the Group implements appropriate safeguards under applicable law and its internal security policies.',
        ],
      },
      {
        title: '7. Retention periods',
        paragraphs: [
          'Data are retained for as long as needed for the purposes pursued, then archived or deleted in accordance with legal obligations and Group retention policies.',
          'As guidance: inactive accounts may be deactivated and later purged under internal procedure; security logs are kept for a limited audit-related period; business data follow the legal timelines applicable to each domain (HR, finance, stock, etc.).',
        ],
      },
      {
        title: '8. Data security',
        paragraphs: [
          'The Group implements technical and organizational measures to protect data against unauthorized access, loss, alteration, or disclosure, including role-based access control, authentication, encrypted communications, logging, backups, and scope segregation.',
          'No system is risk-free; Users must also act prudently and report any suspected incident.',
        ],
      },
      {
        title: '9. Cookies and local storage',
        paragraphs: [
          'LHMS uses local storage mechanisms required for service operation, including authentication token, display language, certain preferences, and the “Remember me” option.',
          'These elements serve technical and convenience purposes. They are not intended for third-party advertising. You may clear local data in your browser settings; this may require signing in again.',
        ],
      },
      {
        title: '10. Individual rights',
        paragraphs: [
          'Under applicable law, you may have rights of access, rectification, erasure, restriction, objection, portability, and the right to set instructions regarding your data.',
          'Requests are handled through the Group’s internal channels. Some requests may be refused or limited where law, system security, or third-party rights so require. Identity verification may be requested where reasonably necessary.',
        ],
      },
      {
        title: '11. Third-party data entered in LHMS',
        paragraphs: [
          'Some modules process data about candidates, members, clients, suppliers, or other third parties. Users who enter such data must ensure collection and use comply with law and Group instructions.',
          'Inaccurate, excessive, or unauthorized information must not be recorded in LHMS.',
        ],
      },
      {
        title: '12. Minors',
        paragraphs: [
          'LHMS is not intended for use by minors. If data relating to minors must be processed in a specific business context, this may only occur under an appropriate legal framework and explicit internal instructions.',
        ],
      },
      {
        title: '13. Changes to this policy',
        paragraphs: [
          'This Privacy Policy may be updated to reflect legal, technical, or organizational changes. The version in force is the one published in LHMS.',
          'In case of material change, Users may be informed by appropriate means.',
        ],
      },
      {
        title: '14. Contact',
        paragraphs: [
          'For questions about this policy or to exercise your rights, contact LHMS administration / Group IT leadership through the designated internal channels.',
        ],
      },
    ],
  },
}
