package pe.vaftec.angelita.payment.service;

import com.fasterxml.jackson.databind.ObjectMapper;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import pe.vaftec.angelita.payment.api.PaymentDtos;
import pe.vaftec.angelita.payment.domain.PaymentAttempt;
import pe.vaftec.angelita.payment.domain.PaymentAttemptRepository;

import java.math.BigDecimal;
import java.util.Optional;

import static org.junit.jupiter.api.Assertions.*;
import static org.mockito.ArgumentMatchers.any;
import static org.mockito.Mockito.*;

class PaymentOrchestratorTest {
    private PaymentAttemptRepository repository;
    private OpenpayClient openpay;
    private LaravelClient laravel;
    private NotificationService notifications;
    private PaymentOrchestrator orchestrator;
    private ObjectMapper mapper;

    @BeforeEach
    void setUp() {
        repository = mock(PaymentAttemptRepository.class);
        openpay = mock(OpenpayClient.class);
        laravel = mock(LaravelClient.class);
        notifications = mock(NotificationService.class);
        mapper = new ObjectMapper();

        when(repository.save(any(PaymentAttempt.class)))
            .thenAnswer(invocation -> invocation.getArgument(0));

        orchestrator = new PaymentOrchestrator(
            repository,
            openpay,
            laravel,
            notifications,
            new PaymentErrorMapper(),
            mapper
        );
    }

    @Test
    void declinedCardReturnsFriendlyFailureAndNotifiesLaravel() throws Exception {
        var request = chargeRequest("ZA-TEST-001-ABC");

        when(repository.findByReference(request.reference())).thenReturn(Optional.empty());
        when(openpay.findByReference(request.reference()))
            .thenReturn(new OpenpayClient.ProviderResponse(200, mapper.readTree("{\"data\":[]}")));
        when(openpay.createCharge(request))
            .thenReturn(new OpenpayClient.ProviderResponse(
                402,
                mapper.readTree("{\"error_code\":\"3004\",\"description\":\"stolen\"}")
            ));
        when(laravel.fail(anyLong(), any()))
            .thenReturn(mapper.createObjectNode());

        var result = orchestrator.charge(request);

        assertEquals(422, result.httpStatus());
        assertEquals("failed", result.body().get("payment_status"));
        assertEquals("La tarjeta fue rechazada.", result.body().get("message"));
        verify(laravel, times(1)).fail(eq(request.order_id()), any());
    }

    @Test
    void existingOpenpayChargeIsRecoveredWithoutPostingDuplicate() throws Exception {
        var request = chargeRequest("ZA-TEST-002-ABC");
        var attempt = PaymentAttempt.create(
            request.order_id(),
            request.order_code(),
            request.reference(),
            request.amount(),
            request.currency(),
            true
        );

        when(repository.findByReference(request.reference())).thenReturn(Optional.of(attempt));
        when(openpay.findByReference(request.reference()))
            .thenReturn(new OpenpayClient.ProviderResponse(
                200,
                mapper.readTree("""
                    {
                      "data": [{
                        "id": "tr_recovered_001",
                        "order_id": "ZA-TEST-002-ABC",
                        "amount": 475.00,
                        "currency": "MXN",
                        "status": "in_progress",
                        "payment_method": {
                          "type": "redirect",
                          "url": "https://openpay.test/3ds"
                        }
                      }]
                    }
                    """)
            ));

        var result = orchestrator.charge(request);

        assertEquals(202, result.httpStatus());
        assertEquals("processing", result.body().get("payment_status"));
        assertEquals("tr_recovered_001", result.body().get("transaction_id"));
        assertEquals(Boolean.TRUE, result.body().get("requires_redirect"));
        verify(openpay, never()).createCharge(any());
    }

    private PaymentDtos.ChargeRequest chargeRequest(String reference) {
        return new PaymentDtos.ChargeRequest(
            501L,
            "ZA-TEST-501",
            reference,
            new BigDecimal("475.00"),
            "MXN",
            "tok_test",
            "device_session_test",
            "https://zapateriangelita.com/checkout?openpay_return=1&order_id=501",
            true,
            new PaymentDtos.Customer("Cliente Prueba", "cliente@example.com", "+525511223344")
        );
    }
}
