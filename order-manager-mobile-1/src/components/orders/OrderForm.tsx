import React, { useState } from 'react';
import { View, Text, TextInput, Button, Alert } from 'react-native';
import SmartAddressInput from '../map/SmartAddressInput';
import { useDispatch } from 'react-redux';
import { addOrder } from '../../store/ordersSlice';

const OrderForm = () => {
    const [name, setName] = useState('');
    const [address, setAddress] = useState('');
    const [phone, setPhone] = useState('');
    const [turkeyQty, setTurkeyQty] = useState(0);
    const [hamQty, setHamQty] = useState(0);
    const [comboQty, setComboQty] = useState(0);
    const [donation, setDonation] = useState(0);
    const dispatch = useDispatch();

    const calculateTotal = () => {
        const totalUnits = turkeyQty + hamQty + comboQty;
        const totalAmount = (totalUnits * 10) + parseFloat(donation);
        return totalAmount;
    };

    const handleSubmit = () => {
        if (!name || !address || !phone) {
            Alert.alert('Error', 'Please fill in all fields');
            return;
        }

        const order = {
            name,
            address,
            phone,
            turkeyQty,
            hamQty,
            comboQty,
            donation,
            totalAmount: calculateTotal(),
        };

        dispatch(addOrder(order));
        Alert.alert('Success', 'Order placed successfully!');
        // Reset form
        setName('');
        setAddress('');
        setPhone('');
        setTurkeyQty(0);
        setHamQty(0);
        setComboQty(0);
        setDonation(0);
    };

    return (
        <View>
            <Text>Order Form</Text>
            <TextInput
                placeholder="Name"
                value={name}
                onChangeText={setName}
            />
            <SmartAddressInput
                placeholder="Customer Address"
                value={address}
                onChangeText={setAddress}
                onAddressSelect={(data) => {
                    setAddress(data.description);
                }}
            />
            <TextInput
                placeholder="Phone Number"
                value={phone}
                onChangeText={setPhone}
                keyboardType="phone-pad"
            />
            <TextInput
                placeholder="Turkey Quantity"
                value={turkeyQty.toString()}
                onChangeText={(text) => setTurkeyQty(parseInt(text) || 0)}
                keyboardType="numeric"
            />
            <TextInput
                placeholder="Ham Quantity"
                value={hamQty.toString()}
                onChangeText={(text) => setHamQty(parseInt(text) || 0)}
                keyboardType="numeric"
            />
            <TextInput
                placeholder="Combo Quantity"
                value={comboQty.toString()}
                onChangeText={(text) => setComboQty(parseInt(text) || 0)}
                keyboardType="numeric"
            />
            <TextInput
                placeholder="Donation Amount"
                value={donation.toString()}
                onChangeText={(text) => setDonation(parseFloat(text) || 0)}
                keyboardType="numeric"
            />
            <Button title="Place Order" onPress={handleSubmit} />
            <Text>Total Amount: ${calculateTotal().toFixed(2)}</Text>
        </View>
    );
};

export default OrderForm;