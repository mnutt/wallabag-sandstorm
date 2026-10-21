<?php
/** A checkpoint committed with the entry, before network-dependent save events. */
final class SandstormImportState
{
    public function __construct(private $em, private string $key) {}

    // false: not started; null: user deleted the entry; Entry: resume save events.
    public function resume(int $userId)
    {
        $id = $this->em->getConnection()->fetchOne(
            'SELECT entry_id FROM sandstorm_import_pending WHERE job_key = ? AND user_id = ?',
            [$this->key, $userId]
        );
        if ($id === false) return false;
        $entry = $this->em->find(\Wallabag\CoreBundle\Entity\Entry::class, $id);
        if (!$entry || $entry->getUser()->getId() !== $userId) return null;
        return $entry;
    }

    public function save($entry, int $userId): void
    {
        $db = $this->em->getConnection();
        $db->beginTransaction();
        try {
            $this->em->flush();
            $db->insert('sandstorm_import_pending', ['job_key' => $this->key, 'user_id' => $userId, 'entry_id' => $entry->getId()]);
            $db->commit();
        } catch (\Throwable $error) {
            $db->rollBack();
            throw $error;
        }
    }

    public function complete(): void
    {
        $this->em->getConnection()->delete('sandstorm_import_pending', ['job_key' => $this->key]);
    }
}
