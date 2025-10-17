import React, { useState } from 'react';
import { View, Text, TextInput, Button, StyleSheet } from 'react-native';

const OrderForm = () => {
    const [item, setItem] = useState('');
    const [quantity, setQuantity] = useState('');
    const [donationAmount, setDonationAmount] = useState('');
    const [paymentMethod, setPaymentMethod] = useState('');

    const handleSubmit = () => {
        // Logic to handle order submission
        console.log({
            item,
            quantity,
            donationAmount,
            paymentMethod,
        });
    };

    return (
        <View style={styles.container}>
            <Text style={styles.title}>Order Form</Text>
            <TextInput
                style={styles.input}
                placeholder="Item"
                value={item}
                onChangeText={setItem}
            />
            <TextInput
                style={styles.input}
                placeholder="Quantity"
                value={quantity}
                onChangeText={setQuantity}
                keyboardType="numeric"
            />
            <TextInput
                style={styles.input}
                placeholder="Donation Amount"
                value={donationAmount}
                onChangeText={setDonationAmount}
                keyboardType="numeric"
            />
            <TextInput
                style={styles.input}
                placeholder="Payment Method"
                value={paymentMethod}
                onChangeText={setPaymentMethod}
            />
            <Button title="Submit Order" onPress={handleSubmit} />
        </View>
    );
};

const styles = StyleSheet.create({
    container: {
        padding: 20,
    },
    title: {
        fontSize: 24,
        marginBottom: 20,
    },
    input: {
        height: 40,
        borderColor: 'gray',
        borderWidth: 1,
        marginBottom: 15,
        paddingLeft: 10,
    },
});

export default OrderForm;