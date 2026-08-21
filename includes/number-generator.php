<?php
/**
 * FieldPlx Tenant Document Number Generator
 *
 * Uses:
 * - tenant_number_sequences
 * - MySQL transactions
 * - SELECT ... FOR UPDATE
 *
 * Compatible with PHP 7.2 and MariaDB/MySQL.
 */

require_once __DIR__ . '/db.php';

/**
 * Supported FieldPlx document types and default prefixes.
 *
 * @return array
 */
if (!function_exists('documentNumberTypes')) {
    function documentNumberTypes()
    {
        return array(
            'job' => 'JOB-',
            'work_order' => 'WO-',
            'request' => 'REQ-',
            'quote' => 'QTN-',
            'invoice' => 'INV-',
            'payment' => 'PAY-',
            'booking' => 'BKG-',
            'visit' => 'VIS-',
            'expense' => 'EXP-',
            'payout' => 'PAYOUT-',
            'invoice_batch' => 'BATCH-'
        );
    }
}

/**
 * Check whether a document type is supported.
 *
 * @param string $documentType
 * @return bool
 */
if (!function_exists('isValidDocumentType')) {
    function isValidDocumentType($documentType)
    {
        $documentTypes = documentNumberTypes();

        return isset($documentTypes[$documentType]);
    }
}

/**
 * Return the default prefix for a document type.
 *
 * @param string $documentType
 * @return string
 */
if (!function_exists('defaultDocumentPrefix')) {
    function defaultDocumentPrefix($documentType)
    {
        $documentTypes = documentNumberTypes();

        return isset($documentTypes[$documentType])
            ? $documentTypes[$documentType]
            : '';
    }
}

/**
 * Return the current reset period.
 *
 * @param string $resetFrequency
 * @return string|null
 */
if (!function_exists('documentResetPeriod')) {
    function documentResetPeriod($resetFrequency)
    {
        if ($resetFrequency === 'yearly') {
            return date('Y');
        }

        if ($resetFrequency === 'monthly') {
            return date('Y-m');
        }

        return null;
    }
}

/**
 * Create a missing tenant sequence.
 *
 * @param int $tenantId
 * @param string $documentType
 * @param string|null $prefix
 * @param int $paddingLength
 * @param string $resetFrequency
 * @return bool
 */
if (!function_exists('createDocumentSequence')) {
    function createDocumentSequence(
        $tenantId,
        $documentType,
        $prefix = null,
        $paddingLength = 6,
        $resetFrequency = 'never'
    ) {
        global $conn;

        $tenantId = (int) $tenantId;
        $documentType = strtolower(trim((string) $documentType));
        $paddingLength = max(1, min(12, (int) $paddingLength));

        $allowedResetFrequencies = array(
            'never',
            'yearly',
            'monthly'
        );

        if (
            $tenantId <= 0 ||
            !isValidDocumentType($documentType) ||
            !in_array(
                $resetFrequency,
                $allowedResetFrequencies,
                true
            )
        ) {
            return false;
        }

        if ($prefix === null || trim($prefix) === '') {
            $prefix = defaultDocumentPrefix($documentType);
        }

        $prefix = substr(trim((string) $prefix), 0, 20);

        $stmt = $conn->prepare("
            INSERT INTO tenant_number_sequences (
                tenant_id,
                document_type,
                prefix,
                next_number,
                padding_length,
                reset_frequency,
                last_reset_period
            ) VALUES (?, ?, ?, 1, ?, ?, NULL)
            ON DUPLICATE KEY UPDATE
                prefix = VALUES(prefix),
                padding_length = VALUES(padding_length),
                reset_frequency = VALUES(reset_frequency)
        ");

        if (!$stmt) {
            error_log(
                'Document sequence preparation failed: ' .
                $conn->error
            );

            return false;
        }

        $stmt->bind_param(
            'issis',
            $tenantId,
            $documentType,
            $prefix,
            $paddingLength,
            $resetFrequency
        );

        $success = $stmt->execute();

        if (!$success) {
            error_log(
                'Document sequence creation failed: ' .
                $stmt->error
            );
        }

        $stmt->close();

        return $success;
    }
}

/**
 * Ensure the tenant has a sequence for the given document type.
 *
 * @param int $tenantId
 * @param string $documentType
 * @return bool
 */
if (!function_exists('ensureDocumentSequence')) {
    function ensureDocumentSequence(
        $tenantId,
        $documentType
    ) {
        global $conn;

        $tenantId = (int) $tenantId;
        $documentType = strtolower(trim((string) $documentType));

        if (
            $tenantId <= 0 ||
            !isValidDocumentType($documentType)
        ) {
            return false;
        }

        $stmt = $conn->prepare("
            SELECT id
            FROM tenant_number_sequences
            WHERE tenant_id = ?
              AND document_type = ?
            LIMIT 1
        ");

        if (!$stmt) {
            error_log(
                'Document sequence check failed: ' .
                $conn->error
            );

            return false;
        }

        $stmt->bind_param(
            'is',
            $tenantId,
            $documentType
        );

        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result->num_rows > 0;

        $stmt->close();

        if ($exists) {
            return true;
        }

        return createDocumentSequence(
            $tenantId,
            $documentType
        );
    }
}

/**
 * Generate the next document number for the logged-in tenant.
 *
 * This function locks the sequence row until the number is incremented,
 * preventing two users from receiving the same document number.
 *
 * @param string $documentType
 * @param bool $autoCreate
 * @return string|null
 */
if (!function_exists('generateDocumentNumber')) {
    function generateDocumentNumber(
        $documentType,
        $autoCreate = true
    ) {
        global $conn;

        $tenantId = currentTenantId();
        $documentType = strtolower(trim((string) $documentType));

        if (
            $tenantId <= 0 ||
            !isValidDocumentType($documentType)
        ) {
            return null;
        }

        if (
            $autoCreate &&
            !ensureDocumentSequence(
                $tenantId,
                $documentType
            )
        ) {
            return null;
        }

        try {
            $conn->begin_transaction();

            $stmt = $conn->prepare("
                SELECT
                    id,
                    prefix,
                    next_number,
                    padding_length,
                    reset_frequency,
                    last_reset_period
                FROM tenant_number_sequences
                WHERE tenant_id = ?
                  AND document_type = ?
                LIMIT 1
                FOR UPDATE
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to prepare sequence query: ' .
                    $conn->error
                );
            }

            $stmt->bind_param(
                'is',
                $tenantId,
                $documentType
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    'Unable to read number sequence: ' .
                    $stmt->error
                );
            }

            $result = $stmt->get_result();
            $sequence = $result->fetch_assoc();

            $stmt->close();

            if (!$sequence) {
                throw new Exception(
                    'Number sequence is not configured for: ' .
                    $documentType
                );
            }

            $sequenceId = (int) $sequence['id'];
            $nextNumber = max(
                1,
                (int) $sequence['next_number']
            );

            $paddingLength = max(
                1,
                (int) $sequence['padding_length']
            );

            $resetFrequency = $sequence['reset_frequency'];
            $currentPeriod = documentResetPeriod(
                $resetFrequency
            );

            if (
                $currentPeriod !== null &&
                $sequence['last_reset_period'] !== $currentPeriod
            ) {
                $nextNumber = 1;
            }

            $generatedNumber =
                (string) $sequence['prefix'] .
                str_pad(
                    (string) $nextNumber,
                    $paddingLength,
                    '0',
                    STR_PAD_LEFT
                );

            $newNextNumber = $nextNumber + 1;

            $stmt = $conn->prepare("
                UPDATE tenant_number_sequences
                SET
                    next_number = ?,
                    last_reset_period = ?
                WHERE id = ?
                  AND tenant_id = ?
            ");

            if (!$stmt) {
                throw new Exception(
                    'Unable to prepare sequence update: ' .
                    $conn->error
                );
            }

            $stmt->bind_param(
                'isii',
                $newNextNumber,
                $currentPeriod,
                $sequenceId,
                $tenantId
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    'Unable to update number sequence: ' .
                    $stmt->error
                );
            }

            if ($stmt->affected_rows !== 1) {
                throw new Exception(
                    'Number sequence was not updated.'
                );
            }

            $stmt->close();
            $conn->commit();

            return $generatedNumber;
        } catch (Exception $exception) {
            $conn->rollback();

            error_log(
                'FieldPlx document number generation failed: ' .
                $exception->getMessage()
            );

            return null;
        }
    }
}

/**
 * Preview the next document number without incrementing it.
 *
 * Use this only for display. Do not save records using a previewed number.
 *
 * @param string $documentType
 * @return string|null
 */
if (!function_exists('previewDocumentNumber')) {
    function previewDocumentNumber($documentType)
    {
        global $conn;

        $tenantId = currentTenantId();
        $documentType = strtolower(trim((string) $documentType));

        if (
            $tenantId <= 0 ||
            !isValidDocumentType($documentType)
        ) {
            return null;
        }

        if (
            !ensureDocumentSequence(
                $tenantId,
                $documentType
            )
        ) {
            return null;
        }

        $stmt = $conn->prepare("
            SELECT
                prefix,
                next_number,
                padding_length,
                reset_frequency,
                last_reset_period
            FROM tenant_number_sequences
            WHERE tenant_id = ?
              AND document_type = ?
            LIMIT 1
        ");

        if (!$stmt) {
            error_log(
                'Document number preview failed: ' .
                $conn->error
            );

            return null;
        }

        $stmt->bind_param(
            'is',
            $tenantId,
            $documentType
        );

        $stmt->execute();
        $result = $stmt->get_result();
        $sequence = $result->fetch_assoc();

        $stmt->close();

        if (!$sequence) {
            return null;
        }

        $nextNumber = max(
            1,
            (int) $sequence['next_number']
        );

        $currentPeriod = documentResetPeriod(
            $sequence['reset_frequency']
        );

        if (
            $currentPeriod !== null &&
            $sequence['last_reset_period'] !== $currentPeriod
        ) {
            $nextNumber = 1;
        }

        return $sequence['prefix'] .
            str_pad(
                (string) $nextNumber,
                max(1, (int) $sequence['padding_length']),
                '0',
                STR_PAD_LEFT
            );
    }
}

/**
 * Update the configuration of a tenant document sequence.
 *
 * @param string $documentType
 * @param string $prefix
 * @param int $paddingLength
 * @param string $resetFrequency
 * @return bool
 */
if (!function_exists('updateDocumentSequence')) {
    function updateDocumentSequence(
        $documentType,
        $prefix,
        $paddingLength = 6,
        $resetFrequency = 'never'
    ) {
        global $conn;

        $tenantId = currentTenantId();
        $documentType = strtolower(trim((string) $documentType));
        $prefix = substr(trim((string) $prefix), 0, 20);
        $paddingLength = max(1, min(12, (int) $paddingLength));

        $allowedResetFrequencies = array(
            'never',
            'yearly',
            'monthly'
        );

        if (
            $tenantId <= 0 ||
            !isValidDocumentType($documentType) ||
            $prefix === '' ||
            !in_array(
                $resetFrequency,
                $allowedResetFrequencies,
                true
            )
        ) {
            return false;
        }

        if (
            !ensureDocumentSequence(
                $tenantId,
                $documentType
            )
        ) {
            return false;
        }

        $stmt = $conn->prepare("
            UPDATE tenant_number_sequences
            SET
                prefix = ?,
                padding_length = ?,
                reset_frequency = ?
            WHERE tenant_id = ?
              AND document_type = ?
        ");

        if (!$stmt) {
            error_log(
                'Document sequence update preparation failed: ' .
                $conn->error
            );

            return false;
        }

        $stmt->bind_param(
            'sisis',
            $prefix,
            $paddingLength,
            $resetFrequency,
            $tenantId,
            $documentType
        );

        $success = $stmt->execute();

        if (!$success) {
            error_log(
                'Document sequence update failed: ' .
                $stmt->error
            );
        }

        $stmt->close();

        return $success;
    }
}

/**
 * Set the next sequence number manually.
 *
 * @param string $documentType
 * @param int $nextNumber
 * @return bool
 */
if (!function_exists('setNextDocumentNumber')) {
    function setNextDocumentNumber(
        $documentType,
        $nextNumber
    ) {
        global $conn;

        $tenantId = currentTenantId();
        $documentType = strtolower(trim((string) $documentType));
        $nextNumber = (int) $nextNumber;

        if (
            $tenantId <= 0 ||
            !isValidDocumentType($documentType) ||
            $nextNumber <= 0
        ) {
            return false;
        }

        if (
            !ensureDocumentSequence(
                $tenantId,
                $documentType
            )
        ) {
            return false;
        }

        $stmt = $conn->prepare("
            UPDATE tenant_number_sequences
            SET next_number = ?
            WHERE tenant_id = ?
              AND document_type = ?
        ");

        if (!$stmt) {
            error_log(
                'Next number update preparation failed: ' .
                $conn->error
            );

            return false;
        }

        $stmt->bind_param(
            'iis',
            $nextNumber,
            $tenantId,
            $documentType
        );

        $success = $stmt->execute();

        if (!$success) {
            error_log(
                'Next document number update failed: ' .
                $stmt->error
            );
        }

        $stmt->close();

        return $success;
    }
}