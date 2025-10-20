import React, { useEffect } from 'react';
import { View, Text, FlatList, Button } from 'react-native';
import { useDispatch, useSelector } from 'react-redux';
import { fetchOrders } from '../store/ordersSlice';
import { useNavigation } from '@react-navigation/native';

const OrdersScreen = () => {
    const dispatch = useDispatch();
    const navigation = useNavigation();
    const orders = useSelector((state) => state.orders.orders);
    const loading = useSelector((state) => state.orders.loading);

    useEffect(() => {
        dispatch(fetchOrders());
    }, [dispatch]);

    const renderOrderItem = ({ item }) => (
        <View>
            <Text>{item.name}</Text>
            <Text>{item.address}</Text>
            <Text>{item.phone}</Text>
            <Button
                title="View Details"
                onPress={() => navigation.navigate('OrderScreen', { orderId: item.id })}
            />
        </View>
    );

    return (
        <View>
            <Text>Orders</Text>
            {loading ? (
                <Text>Loading...</Text>
            ) : (
                <FlatList
                    data={orders}
                    renderItem={renderOrderItem}
                    keyExtractor={(item) => item.id.toString()}
                />
            )}
            <Button
                title="Place New Order"
                onPress={() => navigation.navigate('OrderForm')}
            />
        </View>
    );
};

export default OrdersScreen;