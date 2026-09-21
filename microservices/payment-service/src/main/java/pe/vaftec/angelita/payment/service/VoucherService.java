package pe.vaftec.angelita.payment.service;

import com.fasterxml.jackson.databind.JsonNode;
import com.lowagie.text.*;
import com.lowagie.text.pdf.PdfWriter;
import com.lowagie.text.pdf.PdfPTable;
import org.springframework.stereotype.Service;

import java.io.ByteArrayOutputStream;
import java.math.BigDecimal;

@Service
public class VoucherService {
    public byte[] generate(JsonNode payload) {
        try {
        ByteArrayOutputStream out = new ByteArrayOutputStream();
        Document document = new Document(PageSize.A4, 42, 42, 42, 42);
        PdfWriter.getInstance(document, out);
        document.open();

        Font title = FontFactory.getFont(FontFactory.HELVETICA_BOLD, 18);
        Font heading = FontFactory.getFont(FontFactory.HELVETICA_BOLD, 11);
        Font body = FontFactory.getFont(FontFactory.HELVETICA, 10);

        document.add(new Paragraph("Zapatería Angelita", title));
        document.add(new Paragraph("Comprobante de compra", heading));
        document.add(Chunk.NEWLINE);

        document.add(new Paragraph("Pedido: " + text(payload, "order_code"), body));
        document.add(new Paragraph("Transacción: " + text(payload, "transaction_id"), body));
        document.add(new Paragraph("Cliente: " + text(payload, "customer_name"), body));
        document.add(new Paragraph("Correo: " + text(payload, "customer_email"), body));
        document.add(Chunk.NEWLINE);

        PdfPTable table = new PdfPTable(new float[]{4f, 1.4f, 1.4f, 1.2f, 1.6f});
        table.setWidthPercentage(100);
        for (String h : new String[]{"Producto", "Talla", "Color", "Cant.", "Precio"}) {
            table.addCell(new Phrase(h, heading));
        }

        JsonNode items = payload.path("items");
        if (items.isArray()) {
            for (JsonNode item : items) {
                table.addCell(new Phrase(item.path("name").asText("Producto"), body));
                table.addCell(new Phrase(item.path("size").asText("-"), body));
                table.addCell(new Phrase(item.path("color").asText("-"), body));
                table.addCell(new Phrase(String.valueOf(item.path("quantity").asInt(1)), body));
                table.addCell(new Phrase(money(item.path("unit_price").decimalValue(), text(payload, "currency")), body));
            }
        }
        document.add(table);
        document.add(Chunk.NEWLINE);

        document.add(new Paragraph("Subtotal: " + money(payload.path("subtotal").decimalValue(), text(payload, "currency")), body));
        document.add(new Paragraph("Delivery: " + money(payload.path("shipping_cost").decimalValue(), text(payload, "currency")), body));
        document.add(new Paragraph("Total: " + money(payload.path("total").decimalValue(), text(payload, "currency")), heading));
        document.add(Chunk.NEWLINE);
        document.add(new Paragraph("Gracias por tu compra.", body));

        document.close();
        return out.toByteArray();
        } catch (Exception e) {
            throw new IllegalStateException("Could not generate voucher PDF", e);
        }
    }

    private String text(JsonNode node, String field) {
        return node.path(field).asText("");
    }

    private String money(BigDecimal amount, String currency) {
        return currency + " " + amount.setScale(2, java.math.RoundingMode.HALF_UP);
    }
}
