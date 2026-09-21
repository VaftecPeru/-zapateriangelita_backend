package pe.vaftec.angelita.payment.api;

import com.fasterxml.jackson.databind.JsonNode;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.*;
import pe.vaftec.angelita.payment.service.PaymentOrchestrator;

import java.util.Map;

@RestController
@RequestMapping("/api/v1/webhooks")
public class WebhookController {
    private final PaymentOrchestrator orchestrator;

    public WebhookController(PaymentOrchestrator orchestrator) {
        this.orchestrator = orchestrator;
    }

    @PostMapping("/openpay")
    public ResponseEntity<Map<String, Object>> openpay(@RequestBody JsonNode payload) {
        // Nunca se confía en el status recibido: PaymentOrchestrator vuelve a
        // consultar la transacción directamente contra Openpay antes de conciliar.
        return ResponseEntity.ok(orchestrator.webhook(payload));
    }
}
