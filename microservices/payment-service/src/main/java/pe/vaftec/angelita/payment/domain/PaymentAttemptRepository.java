package pe.vaftec.angelita.payment.domain;

import org.springframework.data.jpa.repository.JpaRepository;
import java.util.Optional;

public interface PaymentAttemptRepository extends JpaRepository<PaymentAttempt, String> {
    Optional<PaymentAttempt> findByReference(String reference);
    Optional<PaymentAttempt> findByTransactionId(String transactionId);
}
