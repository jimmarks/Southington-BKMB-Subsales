import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:my_order_app/screens/login_screen.dart';
import 'package:my_order_app/screens/order_form_screen.dart';
import 'package:my_order_app/screens/order_summary_screen.dart';
import 'package:my_order_app/screens/orders_list_screen.dart';
import 'package:my_order_app/screens/settings_screen.dart';

void main() {
  testWidgets('Login Screen Test', (WidgetTester tester) async {
    await tester.pumpWidget(MaterialApp(home: LoginScreen()));
    expect(find.byType(TextField), findsNWidgets(2)); // Code and Team Name fields
    expect(find.byType(ElevatedButton), findsOneWidget); // Login button
  });

  testWidgets('Order Form Screen Test', (WidgetTester tester) async {
    await tester.pumpWidget(MaterialApp(home: OrderFormScreen()));
    expect(find.byType(TextField), findsNWidgets(3)); // Name, Address, Phone fields
    expect(find.byType(DropdownButton), findsOneWidget); // Item selection
    expect(find.byType(ElevatedButton), findsOneWidget); // Place Order button
  });

  testWidgets('Order Summary Screen Test', (WidgetTester tester) async {
    await tester.pumpWidget(MaterialApp(home: OrderSummaryScreen()));
    expect(find.text('Order Summary'), findsOneWidget);
    expect(find.byType(Text), findsNWidgets(2)); // Total amount and order details
  });

  testWidgets('Orders List Screen Test', (WidgetTester tester) async {
    await tester.pumpWidget(MaterialApp(home: OrdersListScreen()));
    expect(find.byType(ListView), findsOneWidget); // List of past orders
  });

  testWidgets('Settings Screen Test', (WidgetTester tester) async {
    await tester.pumpWidget(MaterialApp(home: SettingsScreen()));
    expect(find.byType(ListTile), findsNWidgets(3)); // Settings options
  });
}