package pe.vaftec.angelita.payment.service;

import org.junit.jupiter.api.Test;
import static org.junit.jupiter.api.Assertions.*;

class PaymentErrorMapperTest {
    private final PaymentErrorMapper mapper = new PaymentErrorMapper();

    @Test
    void stolenAndAntifraudCardsUseGenericRejectedMessage() {
        assertEquals("La tarjeta fue rechazada.", mapper.map("3004").message());
        assertEquals("La tarjeta fue rechazada.", mapper.map("3005").message());
        assertEquals("CARD_DECLINED", mapper.map("3005").key());
    }

    @Test
    void insufficientFundsKeepsSpecificMessage() {
        assertEquals("Fondos insuficientes", mapper.map("3003").title());
    }
}
