<?php
declare(strict_types=1);

class ParametreController extends Controller
{
    private const STATUS_COLORS = [
        'primary', 'secondary', 'success', 'danger', 'warning', 'info', 'dark', 'light',
    ];

    private function ensureAdmin(): void
    {
        Auth::requireLogin();
        Permission::requireOrFail('parametres.manage');
    }

    private function modelFor(string $type): ?Model
    {
        return match ($type) {
            'types_titres' => new TypeTitreModel(),
            'statuts'      => new StatutModel(),
            'localisations'=> new LocalisationModel(),
            default        => null,
        };
    }

    public function index(): void
    {
        $this->ensureAdmin();
        $paramModel = new ParametreModel();
        $statusModel = new StatutModel();

        $this->render('parametres/index', [
            '__title' => 'Configuration',
            '__active' => 'parametres',
            '__hide_page_header' => true,
            'parametres' => $paramModel->tous(),
            'types' => (new TypeTitreModel())->all('ordre', 'ASC'),
            'statuts' => $statusModel->tous(),
            'workflowStatusCodes' => array_keys(StatutModel::WORKFLOW),
            'statusWorkflowHealth' => $statusModel->workflowHealth(),
            'localisations' => (new LocalisationModel())->all('libelle', 'ASC'),
            'reminderHealth' => (new ReminderRunState(Database::getConnection()))->status(),
        ]);
    }

    public function updateGeneral(): void
    {
        $this->ensureAdmin();
        $paramModel = new ParametreModel();

        $appNom = Security::cleanString($this->input('app_nom', 'OIPI - RISFM'));
        $colors = [
            'couleur_primaire' => Security::cleanString($this->input('couleur_primaire', '#F68B1F')),
            'couleur_secondaire' => Security::cleanString($this->input('couleur_secondaire', '#00A651')),
            'couleur_accent' => Security::cleanString($this->input('couleur_accent', '#17352B')),
        ];
        if ($appNom === '' || mb_strlen($appNom) > 100 || array_filter($colors, fn ($c) => !preg_match('/^#[0-9a-fA-F]{6}$/', $c))) {
            setFlash('error', 'Le nom ou les couleurs fournis sont invalides.');
            $this->redirect('parametres');
            return;
        }
        $paramModel->set('app_nom', $appNom);
        foreach ($colors as $key => $value) {
            $paramModel->set($key, strtoupper($value));
        }
        $lifetime = max(5, min(120, (int) $this->input('session_lifetime_minutes', 20)));
        $paramModel->set('session_lifetime_minutes', (string) $lifetime);

        if (!empty($_FILES['logo']['name'])) {
            $file = $_FILES['logo'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $mime = $file['error'] === UPLOAD_ERR_OK
                ? Security::validatedUploadMime($file['tmp_name'], $ext, ['png', 'jpg', 'jpeg'])
                : null;
            if ($mime !== null) {
                $safe = Security::safeFilename($file['name']);
                $directory = UPLOADS_PATH . '/logos';
                if (Security::ensureDirectory($directory) && move_uploaded_file($file['tmp_name'], $directory . '/' . $safe)) {
                    $paramModel->set('app_logo', $safe);
                }
            } else {
                Logger::log(Auth::id(), 'securite', 'Logo refuse : type de fichier invalide');
                setFlash('error', 'Logo invalide (formats acceptes : PNG, JPG, JPEG).');
                $this->redirect('parametres');
                return;
            }
        }

        Logger::log(Auth::id(), 'modification', 'Mise a jour des parametres generaux');
        setFlash('success', 'Parametres generaux mis a jour.');
        $this->redirect('parametres');
    }

    public function addListItem(string $type): void
    {
        $this->ensureAdmin();
        $model = $this->modelFor($type);
        if (!$model) {
            setFlash('error', 'Liste inconnue.');
            $this->redirect('parametres');
            return;
        }
        if ($type === 'statuts') {
            setFlash(
                'error',
                'Le workflow utilise sept statuts système fixes. Seuls leurs libellés et couleurs peuvent être personnalisés.'
            );
            $this->redirect('parametres#listes-metier');
            return;
        }

        try {
            $data = $this->listItemData($type);
            $id = $model->insert($data);
            Logger::log(
                Auth::id(), 'ajout',
                "Ajout d'un element de parametrage [{$type}] : {$data['libelle']}",
                null, 'parametrage_' . $type, $id, null, $data
            );
            setFlash('success', 'Element ajoute.');
        } catch (InvalidArgumentException $e) {
            setFlash('error', $e->getMessage());
        } catch (PDOException $e) {
            setFlash('error', (int) ($e->errorInfo[1] ?? 0) === 1062
                ? 'Ce code ou ce libelle existe deja dans cette liste.'
                : 'L’element n’a pas pu etre ajoute.');
        }
        $this->redirect('parametres#listes-metier');
    }

    public function updateListItem(string $type, string $id): void
    {
        $this->ensureAdmin();
        $model = $this->modelFor($type);
        if (!$model) {
            setFlash('error', 'Liste inconnue.');
            $this->redirect('parametres');
            return;
        }
        $itemId = (int) $id;
        $existing = $model->find($itemId);
        if (!$existing) {
            setFlash('error', 'Element de parametrage introuvable.');
            $this->redirect('parametres#listes-metier');
            return;
        }
        if ($type === 'statuts' && !StatutModel::isWorkflowCode((string) ($existing['code'] ?? ''))) {
            setFlash(
                'error',
                'Cet ancien statut est conservé uniquement pour l’historique et ne peut plus être modifié.'
            );
            $this->redirect('parametres#listes-metier');
            return;
        }

        try {
            $data = $this->listItemData($type, $existing);
            $model->update($itemId, $data);
            Logger::log(
                Auth::id(), 'modification',
                "Modification d'un element de parametrage [{$type}] #{$itemId}",
                null, 'parametrage_' . $type, $itemId, $existing, array_merge($existing, $data)
            );
            setFlash('success', 'Element modifie.');
        } catch (InvalidArgumentException $e) {
            setFlash('error', $e->getMessage());
        } catch (PDOException $e) {
            setFlash('error', (int) ($e->errorInfo[1] ?? 0) === 1062
                ? 'Ce libelle existe deja dans cette liste.'
                : 'La modification n’a pas pu etre enregistree.');
        }
        $this->redirect('parametres#listes-metier');
    }

    public function toggleListItem(string $type, string $id): void
    {
        $this->ensureAdmin();
        $model = $this->modelFor($type);
        if (!$model) {
            setFlash('error', 'Liste inconnue.');
            $this->redirect('parametres');
            return;
        }

        $itemId = (int) $id;
        $existing = $model->find($itemId);
        if (!$existing) {
            setFlash('error', 'Element de parametrage introuvable.');
            $this->redirect('parametres#listes-metier');
            return;
        }

        $nextActive = (int) ($existing['actif'] ?? 1) === 1 ? 0 : 1;
        if ($type === 'statuts') {
            $isSystem = StatutModel::isWorkflowCode((string) ($existing['code'] ?? ''));
            if ($isSystem) {
                setFlash('error', 'Ce statut système est indispensable au workflow et reste toujours actif.');
                $this->redirect('parametres#listes-metier');
                return;
            }
            if ($nextActive === 1) {
                setFlash(
                    'error',
                    'Un ancien statut hors workflow est conservé pour l’historique mais ne peut pas être réactivé.'
                );
                $this->redirect('parametres#listes-metier');
                return;
            }
        }

        $model->update($itemId, ['actif' => $nextActive]);
        Logger::log(
            Auth::id(),
            $nextActive === 1 ? 'activation' : 'desactivation',
            ($nextActive === 1 ? 'Activation' : 'Desactivation') . " d'un element [{$type}] #{$itemId}",
            null,
            'parametrage_' . $type,
            $itemId,
            ['actif' => (int) ($existing['actif'] ?? 1)],
            ['actif' => $nextActive]
        );
        setFlash('success', $nextActive === 1 ? 'Element active.' : 'Element desactive.');
        $this->redirect('parametres#listes-metier');
    }

    private function listItemData(string $type, ?array $existing = null): array
    {
        $libelle = Security::cleanString($this->input('libelle', ''));
        $maxLength = $type === 'localisations' ? 150 : 100;
        if ($libelle === '' || mb_strlen($libelle) > $maxLength) {
            throw new InvalidArgumentException("Le libelle est obligatoire et limite a {$maxLength} caracteres.");
        }

        $data = ['libelle' => $libelle];
        if ($type === 'localisations') {
            return $data;
        }

        if ($type === 'statuts') {
            if ($existing === null) {
                throw new InvalidArgumentException(
                    'Les étapes du workflow sont fixes. Aucun statut libre ne peut être ajouté.'
                );
            }
            $couleur = Security::cleanString($this->input('couleur', 'secondary'));
            if (!in_array($couleur, self::STATUS_COLORS, true)) {
                throw new InvalidArgumentException('La couleur de statut sélectionnée est invalide.');
            }
            return [
                'libelle' => $libelle,
                'couleur' => $couleur,
                // Ces valeurs pilotent le code : toute tentative de les
                // falsifier depuis le navigateur est explicitement ignorée.
                'resolu' => StatutModel::expectedResolved((string) ($existing['code'] ?? ''))
                    ?? (int) ($existing['resolu'] ?? 0),
                'ordre' => (int) ($existing['ordre'] ?? 0),
            ];
        }

        if ($existing === null) {
            // Le code est une cle interne : il n'est jamais accepte depuis le
            // navigateur et reste stable apres la creation de l'element.
            $data['code'] = $this->generateUniqueTypeCode($libelle);
        }
        $data['ordre'] = max(-32768, min(32767, (int) $this->input('ordre', $existing['ordre'] ?? 0)));

        return $data;
    }

    private function generateUniqueTypeCode(string $label): string
    {
        $ascii = $label;
        if (function_exists('transliterator_transliterate')) {
            $transliterated = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $label);
            if (is_string($transliterated) && $transliterated !== '') {
                $ascii = $transliterated;
            }
        } elseif (function_exists('iconv')) {
            $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label);
            if (is_string($transliterated) && $transliterated !== '') {
                $ascii = strtolower($transliterated);
            }
        }

        $base = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $ascii), '_'));
        if ($base === '') {
            $base = 'type_titre';
        } elseif (!preg_match('/^[a-z]/', $base)) {
            $base = 'type_' . $base;
        }
        $base = substr($base, 0, 40);

        $model = new TypeTitreModel();
        $candidate = $base;
        $suffix = 2;
        while ($model->count('code = :code', ['code' => $candidate]) > 0) {
            $ending = '_' . $suffix;
            $candidate = substr($base, 0, 40 - strlen($ending)) . $ending;
            $suffix++;
        }

        return $candidate;
    }
}
